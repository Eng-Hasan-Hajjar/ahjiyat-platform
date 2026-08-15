<?php

namespace App\Http\Controllers;

use App\Models\{Rating, Project, User, VolunteerProfile};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RatingController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'rated_user_id' => 'required|exists:users,id',
            'type'          => 'required|in:volunteer_rating,project_rating,owner_rating',
            'rating'        => 'required|integer|min:1|max:5',
            'comment'       => 'nullable|string|max:500',
        ]);

        // Prevent duplicate rating
        $exists = Rating::where('project_id', $project->id)
                        ->where('rater_id', Auth::id())
                        ->where('rated_user_id', $data['rated_user_id'])
                        ->exists();

        if ($exists) {
            return back()->with('error', 'لقد قمت بتقييم هذا المستخدم مسبقاً.');
        }

        $data['project_id'] = $project->id;
        $data['rater_id']   = Auth::id();

        Rating::create($data);

        // Update volunteer's average rating
        if (in_array($data['type'], ['volunteer_rating'])) {
            $ratedUser = User::find($data['rated_user_id']);
            if ($ratedUser && $ratedUser->isVolunteer()) {
                $profile = $ratedUser->volunteerProfile;
                if ($profile) {
                    $avg = Rating::where('rated_user_id', $ratedUser->id)
                                 ->where('type', 'volunteer_rating')
                                 ->avg('rating');
                    $count = Rating::where('rated_user_id', $ratedUser->id)
                                   ->where('type', 'volunteer_rating')
                                   ->count();
                    $profile->update(['rating' => round($avg, 2), 'rating_count' => $count]);
                }
            }
        }

        return back()->with('success', 'تم إرسال تقييمك بنجاح. شكراً!');
    }
}