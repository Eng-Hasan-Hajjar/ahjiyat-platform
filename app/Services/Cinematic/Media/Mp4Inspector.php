<?php

namespace App\Services\Cinematic\Media;

use App\Services\Cinematic\CinematicException;
use SplFileObject;
use Throwable;

/**
 * CIN-1 - فاحص MP4 بـPHP نقي (بلا FFmpeg/ffprobe ولا shell: تدقيق E26-W6 يمنع تنفيذ الأوامر، ولا نفترض وجود FFmpeg على الاستضافة).
 *
 * يقرأ بنية ISO-BMFF (صناديق ftyp/moov/mvhd/trak/tkhd/mdia/hdlr/minf/stbl/stsd) ويستخرج: العلامة التجارية، المدة، الأبعاد، ترميز الفيديو (avc1/avc3=H.264)
 * وترميز الصوت (mp4a بنوع كائن AAC)، وترتيب moov/mdat (البدء السريع). لا يثق بالامتداد ولا بترويسة المتصفح. كل قراءة محدودة بحدود صارمة
 * (عدد صناديق، عمق، حجم moov) حتى لا يُستغل ملف خبيث لاستهلاك الذاكرة أو الدوران اللانهائي.
 *
 * هذا فحص بنية وخصائص وليس بديلًا عن فك ترميز كامل: لا يضمن سلامة كل إطار. يُعلن ذلك في تقرير الأمان.
 */
final class Mp4Inspector
{
    private const MAX_TOP_BOXES = 4096;

    private const MAX_MOOV_BYTES = 33554432; // 32MiB

    private const MAX_DEPTH = 8;

    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'dinf'];

    /**
     * @return array{brand: string, brands: list<string>, duration_ms: int, width: int, height: int, video_codec: ?string, audio_codec: ?string, faststart: bool, fragmented: bool, tracks: int}
     */
    public function inspect(string $path): array
    {
        try {
            return $this->doInspect($path);
        } catch (CinematicException $e) {
            throw $e;
        } catch (Throwable) {
            throw CinematicException::make('mp4_unreadable', 'تعذّر قراءة بنية ملف الفيديو؛ الملف تالف أو ليس MP4 صالحًا.');
        }
    }

    /** @return array<string, mixed> */
    private function doInspect(string $path): array
    {
        $file = new SplFileObject($path, 'rb');
        $size = (int) $file->getSize();

        if ($size < 32) {
            throw CinematicException::make('mp4_too_small', 'ملف الفيديو صغير جدًا ليكون MP4 صالحًا.');
        }

        $offset = 0;
        $boxes = 0;
        $ftyp = null;
        $moovAt = null;
        $mdatAt = null;
        $moovData = null;

        while ($offset + 8 <= $size) {
            if (++$boxes > self::MAX_TOP_BOXES) {
                throw CinematicException::make('mp4_structure', 'بنية ملف الفيديو غير معتادة (صناديق كثيرة جدًا).');
            }

            $file->fseek($offset);
            $head = (string) $file->fread(16);

            if (strlen($head) < 8) {
                break;
            }

            $len = (int) unpack('N', substr($head, 0, 4))[1];
            $type = substr($head, 4, 4);
            $header = 8;

            if ($len === 1) {
                if (strlen($head) < 16) {
                    throw CinematicException::make('mp4_structure', 'رأس صندوق MP4 ناقص.');
                }

                $parts = unpack('N2', substr($head, 8, 8));
                $len = ((int) $parts[1] << 32) | (int) $parts[2];
                $header = 16;
            } elseif ($len === 0) {
                $len = $size - $offset;
            }

            if ($len < $header || $offset + $len > $size) {
                throw CinematicException::make('mp4_truncated', 'ملف الفيديو مقطوع أو بنيته غير مكتملة.');
            }

            if ($type === 'ftyp' && $ftyp === null) {
                $file->fseek($offset + $header);
                $ftyp = (string) $file->fread(min($len - $header, 256));
            } elseif ($type === 'moov' && $moovAt === null) {
                $moovAt = $offset;
                $payload = $len - $header;

                if ($payload > self::MAX_MOOV_BYTES) {
                    throw CinematicException::make('mp4_structure', 'قسم البيانات الوصفية في ملف الفيديو أكبر من الحد المسموح.');
                }

                $file->fseek($offset + $header);
                $moovData = (string) $file->fread($payload);
            } elseif ($type === 'mdat' && $mdatAt === null) {
                $mdatAt = $offset;
            }

            $offset += $len;
        }

        if ($ftyp === null || strlen($ftyp) < 8) {
            throw CinematicException::make('mp4_no_ftyp', 'الملف ليس MP4 صالحًا (لا ترويسة نوع الملف).');
        }

        if ($moovData === null) {
            throw CinematicException::make('mp4_no_moov', 'ملف الفيديو بلا قسم بيانات وصفية (moov) فلا يمكن التحقق منه.');
        }

        $brand = substr($ftyp, 0, 4);
        $brands = [$brand];

        for ($i = 8; $i + 4 <= strlen($ftyp); $i += 4) {
            $brands[] = substr($ftyp, $i, 4);
        }

        $info = $this->parseMoov($moovData);

        return [
            'brand' => $brand,
            'brands' => array_values(array_unique($brands)),
            'duration_ms' => $info['duration_ms'],
            'width' => $info['width'],
            'height' => $info['height'],
            'video_codec' => $info['video_codec'],
            'audio_codec' => $info['audio_codec'],
            'faststart' => $mdatAt === null || $moovAt < $mdatAt,
            'fragmented' => $info['fragmented'],
            'tracks' => $info['tracks'],
        ];
    }

    /** @return array{duration_ms: int, width: int, height: int, video_codec: ?string, audio_codec: ?string, fragmented: bool, tracks: int} */
    private function parseMoov(string $moov): array
    {
        $durationMs = 0;
        $width = 0;
        $height = 0;
        $videoCodec = null;
        $audioCodec = null;
        $tracks = 0;
        $fragmented = false;

        foreach ($this->children($moov, 0, strlen($moov), 1) as [$type, $start, $end]) {
            if ($type === 'mvhd') {
                $durationMs = $this->movieDurationMs(substr($moov, $start, $end - $start));
            } elseif ($type === 'mvex') {
                $fragmented = true;
            } elseif ($type === 'trak') {
                $tracks++;
                $track = $this->parseTrak($moov, $start, $end);

                if ($track['handler'] === 'vide' && $videoCodec === null) {
                    $videoCodec = $track['codec'];
                    $width = $track['width'];
                    $height = $track['height'];
                } elseif ($track['handler'] === 'soun' && $audioCodec === null) {
                    $audioCodec = $track['codec'];
                }
            }
        }

        if ($durationMs <= 0) {
            throw CinematicException::make('mp4_duration', 'تعذّر تحديد مدة الفيديو (مدة غير صالحة أو ملف مجزَّأ بلا مدة).');
        }

        return ['duration_ms' => $durationMs, 'width' => $width, 'height' => $height, 'video_codec' => $videoCodec, 'audio_codec' => $audioCodec, 'fragmented' => $fragmented, 'tracks' => $tracks];
    }

    private function movieDurationMs(string $mvhd): int
    {
        if (strlen($mvhd) < 20) {
            return 0;
        }

        $version = ord($mvhd[0]);

        if ($version === 1) {
            if (strlen($mvhd) < 32) {
                return 0;
            }

            $timescale = (int) unpack('N', substr($mvhd, 20, 4))[1];
            $parts = unpack('N2', substr($mvhd, 24, 8));
            $duration = ((int) $parts[1] << 32) | (int) $parts[2];
        } else {
            $timescale = (int) unpack('N', substr($mvhd, 12, 4))[1];
            $duration = (int) unpack('N', substr($mvhd, 16, 4))[1];
        }

        if ($timescale <= 0 || $duration <= 0) {
            return 0;
        }

        return (int) round($duration * 1000 / $timescale);
    }

    /** @return array{handler: ?string, codec: ?string, width: int, height: int} */
    private function parseTrak(string $data, int $start, int $end): array
    {
        $handler = null;
        $codec = null;
        $width = 0;
        $height = 0;

        foreach ($this->children($data, $start, $end, 2) as [$type, $s, $e]) {
            if ($type === 'tkhd' && $e - $s >= 84) {
                $payload = substr($data, $s, $e - $s);
                $width = (int) (unpack('N', substr($payload, -8, 4))[1] >> 16);
                $height = (int) (unpack('N', substr($payload, -4, 4))[1] >> 16);
            } elseif ($type === 'mdia') {
                foreach ($this->children($data, $s, $e, 3) as [$mt, $ms, $me]) {
                    if ($mt === 'hdlr' && $me - $ms >= 12) {
                        $handler = substr($data, $ms + 8, 4);
                    } elseif ($mt === 'minf') {
                        $codec = $this->sampleCodec($data, $ms, $me);
                    }
                }
            }
        }

        return ['handler' => $handler, 'codec' => $codec, 'width' => $width, 'height' => $height];
    }

    private function sampleCodec(string $data, int $start, int $end): ?string
    {
        foreach ($this->children($data, $start, $end, 4) as [$type, $s, $e]) {
            if ($type !== 'stbl') {
                continue;
            }

            foreach ($this->children($data, $s, $e, 5) as [$bt, $bs, $be]) {
                if ($bt !== 'stsd' || $be - $bs < 16) {
                    continue;
                }

                $entryStart = $bs + 8;
                $entryType = substr($data, $entryStart + 4, 4);
                $entryEnd = min($be, $entryStart + (int) unpack('N', substr($data, $entryStart, 4))[1]);

                if ($entryType === 'mp4a') {
                    return $this->audioObjectType($data, $entryStart, $entryEnd);
                }

                return $entryType;
            }
        }

        return null;
    }

    /** يُرجع 'mp4a' عند AAC، و'mp4a:other' لغيره (mp3 داخل MP4، إلخ)، و'mp4a:unknown' إن تعذّر القراءة. */
    private function audioObjectType(string $data, int $entryStart, int $entryEnd): string
    {
        $version = $entryStart + 8 + 8 + 1 < $entryEnd ? (int) unpack('n', substr($data, $entryStart + 8 + 8, 2))[1] : 0;
        $extra = $version === 1 ? 16 : ($version === 2 ? 36 : 0);
        $childStart = $entryStart + 8 + 28 + $extra;

        if ($childStart >= $entryEnd) {
            return 'mp4a:unknown';
        }

        foreach ($this->children($data, $childStart, $entryEnd, 6) as [$type, $s, $e]) {
            if ($type !== 'esds') {
                continue;
            }

            $esds = substr($data, $s + 4, $e - $s - 4);
            $pos = strpos($esds, "\x04");

            if ($pos === false) {
                return 'mp4a:unknown';
            }

            $p = $pos + 1;

            // طول واصف قابل للتغيّر (حتى 4 بايتات)
            for ($i = 0; $i < 4 && $p < strlen($esds); $i++) {
                $b = ord($esds[$p++]);

                if (($b & 0x80) === 0) {
                    break;
                }
            }

            if ($p >= strlen($esds)) {
                return 'mp4a:unknown';
            }

            $objectType = ord($esds[$p]);

            return in_array($objectType, [0x40, 0x66, 0x67, 0x68], true) ? 'mp4a' : 'mp4a:other';
        }

        return 'mp4a:unknown';
    }

    /**
     * @return \Generator<int, array{0: string, 1: int, 2: int}> [type, بداية المحتوى، نهايته]
     */
    private function children(string $data, int $start, int $end, int $depth): \Generator
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $pos = $start;
        $count = 0;

        while ($pos + 8 <= $end && $count++ < 2048) {
            $len = (int) unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            $header = 8;

            if ($len === 1) {
                if ($pos + 16 > $end) {
                    return;
                }

                $parts = unpack('N2', substr($data, $pos + 8, 8));
                $len = ((int) $parts[1] << 32) | (int) $parts[2];
                $header = 16;
            } elseif ($len === 0) {
                $len = $end - $pos;
            }

            if ($len < $header || $pos + $len > $end) {
                return;
            }

            yield [$type, $pos + $header, $pos + $len];

            $pos += $len;
        }
    }
}
