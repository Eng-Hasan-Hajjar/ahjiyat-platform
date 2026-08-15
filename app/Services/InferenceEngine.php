<?php

namespace App\Services;

use App\Models\Rule;
use App\Models\Fault;
use App\Models\Symptom;

/**
 * Rule-Based Forward Chaining Inference Engine
 * Implements MYCIN-style Certainty Factor algebra.
 *
 * CF_combine(a, b) = a + b - a*b  (both positive evidence)
 * CF_combine(a, b) = a + b + a*b  (both negative)
 * CF_combine(a, b) = (a+b) / (1 - min(|a|,|b|))  (mixed)
 */
class InferenceEngine
{
    /**
     * Run forward chaining over the selected symptom IDs.
     *
     * @param  array  $selectedSymptomIds
     * @return array  Ranked list of [ fault_id, fault, cf, matched_symptoms, explanation ]
     */
    public function diagnose(array $selectedSymptomIds): array
    {
        if (empty($selectedSymptomIds)) {
            return [];
        }

        // Load all active rules eagerly with fault
        $rules = Rule::with('fault.symptoms')
                     ->active()
                     ->get();

        // Accumulate CF per fault
        $faultCF = [];      // fault_id => combined CF
        $faultMeta = [];    // fault_id => { fault, matched_symptoms, firing_rules }

        foreach ($rules as $rule) {
            if (!$rule->fires($selectedSymptomIds)) {
                continue;
            }

            $faultId = $rule->fault_id;

            // How many required symptoms actually match (for partial AND bonus)
            $requiredIds   = $rule->condition_symptom_ids ?? [];
            $matchedIds    = array_values(array_intersect($requiredIds, $selectedSymptomIds));
            $matchRatio    = count($requiredIds) > 0
                             ? count($matchedIds) / count($requiredIds)
                             : 1;

            // Weighted CF: base CF × match ratio
            $ruleCF = $rule->cf * $matchRatio;

            if (!isset($faultCF[$faultId])) {
                $faultCF[$faultId]  = $ruleCF;
                $faultMeta[$faultId] = [
                    'fault'            => $rule->fault,
                    'matched_symptom_ids' => $matchedIds,
                    'firing_rules'     => [$rule->id],
                ];
            } else {
                // Combine CFs using MYCIN algebra (positive evidence path)
                $faultCF[$faultId] = $this->combineCF($faultCF[$faultId], $ruleCF);
                $faultMeta[$faultId]['matched_symptom_ids'] = array_unique(
                    array_merge($faultMeta[$faultId]['matched_symptom_ids'], $matchedIds)
                );
                $faultMeta[$faultId]['firing_rules'][] = $rule->id;
            }
        }

        // Build result array
        $results = [];
        foreach ($faultCF as $faultId => $cf) {
            $meta  = $faultMeta[$faultId];
            $fault = $meta['fault'];

            // Load matched symptom names
            $matchedSymptoms = Symptom::whereIn('id', $meta['matched_symptom_ids'])
                                      ->pluck('name_ar', 'id')
                                      ->toArray();

            $results[] = [
                'fault_id'         => $faultId,
                'fault_name'       => $fault->name_ar,
                'fault_name_en'    => $fault->name,
                'severity'         => $fault->severity,
                'severity_label'   => $fault->severity_label,
                'severity_color'   => $fault->severity_color,
                'category'         => $fault->category,
                'description'      => $fault->description_ar,
                'repair_steps'     => $fault->repair_steps_array,
                'cf'               => round(min($cf, 1.0), 3),
                'cf_percent'       => round(min($cf, 1.0) * 100, 1),
                'matched_symptoms' => $matchedSymptoms,
                'explanation'      => $this->buildExplanation($fault, $matchedSymptoms, $cf),
                'cost_min'         => $fault->avg_repair_cost_min,
                'cost_max'         => $fault->avg_repair_cost_max,
            ];
        }

        // Sort descending by CF
        usort($results, fn($a, $b) => $b['cf'] <=> $a['cf']);

        return $results;
    }

    /**
     * MYCIN Certainty Factor combination (both positive).
     */
    private function combineCF(float $a, float $b): float
    {
        if ($a >= 0 && $b >= 0) {
            return $a + $b - ($a * $b);
        }
        if ($a < 0 && $b < 0) {
            return $a + $b + ($a * $b);
        }
        $min = min(abs($a), abs($b));
        if ($min == 1) return 0;
        return ($a + $b) / (1 - $min);
    }

    /**
     * Generate human-readable explanation (Why + How).
     */
    private function buildExplanation(Fault $fault, array $matchedSymptoms, float $cf): string
    {
        $symptomList = implode('، ', array_values($matchedSymptoms));
        $cfPct       = round(min($cf, 1.0) * 100, 1);

        return "تم اقتراح عطل \"{$fault->name_ar}\" استناداً إلى الأعراض التالية: {$symptomList}. "
             . "درجة الثقة في هذا التشخيص هي {$cfPct}%، وذلك بناءً على تطابق الأعراض مع قواعد قاعدة المعرفة.";
    }
}