<?php

namespace Ophim\Crawler\OphimCrawler;

use Ophim\Core\Models\Movie;
use Illuminate\Support\Str;
use Ophim\Core\Models\Actor;
use Ophim\Core\Models\Category;
use Ophim\Core\Models\Director;
use Ophim\Core\Models\Episode;
use Ophim\Core\Models\Region;
use Ophim\Core\Models\Tag;
use Ophim\Crawler\OphimCrawler\Contracts\BaseCrawler;

class Crawler extends BaseCrawler
{
    public function handle()
    {
        // Mã hóa URL nếu chứa ký tự Unicode
        $encodedUrl = $this->encodeUrl($this->link);
        
        // Sử dụng cURL thay vì file_get_contents để xử lý tốt hơn các URL có ký tự Unicode
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $encodedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36");
        $body = curl_exec($ch);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            throw new \Exception("Không thể tải dữ liệu từ API. HTTP code: $httpCode, URL: $encodedUrl");
        }
        
        curl_close($ch);
        
        $payload = json_decode($body, true);
        
        if (!$payload || !isset($payload['movie'])) {
            throw new \Exception("Dữ liệu API không hợp lệ hoặc không có thông tin phim");
        }

        $this->checkIsInExcludedList($payload);

        $movie = Movie::where('update_handler', static::class)
            ->where('update_identity', $payload['movie']['_id'])
            ->first();

        if (!$this->hasChange($movie, md5($body)) && $this->forceUpdate == false) {
            return false;
        }

        $info = (new Collector($payload, $this->fields, $this->forceUpdate))->get();

        // Lấy thời gian từ nguồn API
        $created_at = null;
        $updated_at = null;
        
        // Kiểm tra có thời gian tạo trong payload không
        if (isset($payload['movie']['created']['time'])) {
            $created_at = new \DateTime($payload['movie']['created']['time']);
        }
        
        // Kiểm tra có thời gian cập nhật trong payload không
        if (isset($payload['movie']['modified']['time'])) {
            $updated_at = new \DateTime($payload['movie']['modified']['time']);
        }
        
        // Nếu không có thời gian từ API, sử dụng thời gian hiện tại
        if (is_null($created_at)) {
            $created_at = now();
        }
        
        if (is_null($updated_at)) {
            $updated_at = now();
        }

        if ($movie) {
            // Cập nhật movie với thời gian từ API
            $movie->updated_at = $updated_at;
            $movie->update(collect($info)->only($this->fields)->merge(['update_checksum' => md5($body)])->toArray());
        } else {
            // Tạo movie mới với thời gian từ API
            $movie = Movie::create(array_merge($info, [
                'update_handler' => static::class,
                'update_identity' => $payload['movie']['_id'],
                'update_checksum' => md5($body),
                'created_at' => $created_at,
                'updated_at' => $updated_at
            ]));
        }

        $this->syncActors($movie, $payload);
        $this->syncDirectors($movie, $payload);
        $this->syncCategories($movie, $payload);
        $this->syncRegions($movie, $payload);
        $this->syncTags($movie, $payload);
        $this->syncStudios($movie, $payload);
        $this->updateEpisodes($movie, $payload);
    }

    /**
     * Mã hóa URL có chứa ký tự Unicode
     */
    protected function encodeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['path'])) {
            return $url;
        }
        
        // Tách đường dẫn thành các phần
        $pathSegments = explode('/', $parts['path']);
        
        // Mã hóa từng phần (trừ phần đầu tiên là empty do đường dẫn bắt đầu bằng /)
        foreach ($pathSegments as $i => $segment) {
            if ($segment === '') continue;
            
            // Chỉ mã hóa nếu có ký tự không phải ASCII
            if (preg_match('/[^\x20-\x7f]/', $segment)) {
                $pathSegments[$i] = rawurlencode($segment);
            }
        }
        
        // Tái tạo đường dẫn
        $encodedPath = implode('/', $pathSegments);
        
        // Tái tạo URL
        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        $result .= $encodedPath;
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }
        
        return $result;
    }

    protected function hasChange(?Movie $movie, $checksum)
    {
        return is_null($movie) || ($movie->update_checksum != $checksum);
    }

    protected function checkIsInExcludedList($payload)
    {
        $newType = $payload['movie']['type'];
        if (in_array($newType, $this->excludedType)) {
            throw new \Exception("Thuộc định dạng đã loại trừ");
        }

        $newCategories = collect($payload['movie']['category'])->pluck('name')->toArray();
        if (array_intersect($newCategories, $this->excludedCategories)) {
            throw new \Exception("Thuộc thể loại đã loại trừ");
        }

        $newRegions = collect($payload['movie']['country'])->pluck('name')->toArray();
        if (array_intersect($newRegions, $this->excludedRegions)) {
            throw new \Exception("Thuộc quốc gia đã loại trừ");
        }
    }

    protected function syncActors($movie, array $payload)
    {
        if (!in_array('actors', $this->fields)) return;

        $actors = [];
        foreach ($payload['movie']['actor'] as $actor) {
            if (!trim($actor)) continue;
            
            try {
                // Tìm diễn viên theo tên chuẩn hóa để tránh trùng lặp
                $normalizedName = $this->normalizeActorName(trim($actor));
                $actorModel = Actor::firstOrCreate(
                    ['name' => $normalizedName],
                    [
                        'name' => trim($actor), // Giữ tên hiển thị gốc
                        'slug' => Str::slug($normalizedName . '-' . time()) // Thêm timestamp để đảm bảo slug độc nhất
                    ]
                );
                
                $actors[] = $actorModel->id;
            } catch (\Exception $e) {
                // Log lỗi nhưng không dừng quá trình
                \Log::error("Lỗi khi đồng bộ diễn viên: {$e->getMessage()}, Diễn viên: {$actor}");
                continue;
            }
        }
        
        // Đồng bộ diễn viên với phim
        if (!empty($actors)) {
            $movie->actors()->sync($actors);
        }
    }
    
    /**
     * Chuẩn hóa tên diễn viên để tránh trùng lặp
     */
    protected function normalizeActorName(string $name): string
    {
        // Loại bỏ các khoảng trắng thừa và chuyển về chữ thường
        $normalized = mb_strtolower(trim($name));
        
        // Loại bỏ dấu câu, dấu ngoặc, và các ký tự đặc biệt
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        
        // Thay thế nhiều khoảng trắng bằng một khoảng trắng
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return trim($normalized);
    }

    protected function syncDirectors($movie, array $payload)
    {
        if (!in_array('directors', $this->fields)) return;

        $directors = [];
        foreach ($payload['movie']['director'] as $director) {
            if (!trim($director)) continue;
            $directors[] = Director::firstOrCreate(['name' => trim($director)])->id;
        }
        $movie->directors()->sync($directors);
    }

    protected function syncCategories($movie, array $payload)
    {
        if (!in_array('categories', $this->fields)) return;
        $categories = [];
        foreach ($payload['movie']['category'] as $category) {
            if (!trim($category['name'])) continue;
            $categories[] = Category::firstOrCreate(['name' => trim($category['name'])])->id;
        }
        if($payload['movie']['type'] === 'hoathinh') $categories[] = Category::firstOrCreate(['name' => 'Hoạt Hình'])->id;
        if($payload['movie']['type'] === 'tvshows') $categories[] = Category::firstOrCreate(['name' => 'TV Shows'])->id;
        $movie->categories()->sync($categories);
    }

    protected function syncRegions($movie, array $payload)
    {
        if (!in_array('regions', $this->fields)) return;

        $regions = [];
        foreach ($payload['movie']['country'] as $region) {
            if (!trim($region['name'])) continue;
            $regions[] = Region::firstOrCreate(['name' => trim($region['name'])])->id;
        }
        $movie->regions()->sync($regions);
    }

    protected function syncTags($movie, array $payload)
    {
        if (!in_array('tags', $this->fields)) return;

        $tags = [];
        $tags[] = Tag::firstOrCreate(['name' => trim($movie->name)])->id;
        $tags[] = Tag::firstOrCreate(['name' => trim($movie->origin_name)])->id;

        $movie->tags()->sync($tags);
    }

    protected function syncStudios($movie, array $payload)
    {
        if (!in_array('studios', $this->fields)) return;
    }

    protected function updateEpisodes($movie, $payload)
    {
        if (!in_array('episodes', $this->fields)) return;
        $flag = 0;
        foreach ($payload['episodes'] as $server) {
            foreach ($server['server_data'] as $episode) {
                if ($episode['link_m3u8']) {
                    Episode::updateOrCreate([
                        'id' => $movie->episodes[$flag]->id ?? null
                    ], [
                        'name' => $episode['name'],
                        'movie_id' => $movie->id,
                        'server' => $server['server_name'],
                        'type' => 'm3u8',
                        'link' => $episode['link_m3u8'],
                        'slug' => 'tap-' . Str::slug($episode['name'])
                    ]);
                    $flag++;
                }
                if ($episode['link_embed']) {
                    Episode::updateOrCreate([
                        'id' => $movie->episodes[$flag]->id ?? null
                    ], [
                        'name' => $episode['name'],
                        'movie_id' => $movie->id,
                        'server' => $server['server_name'],
                        'type' => 'embed',
                        'link' => $episode['link_embed'],
                        'slug' => 'tap-' . Str::slug($episode['name'])
                    ]);
                    $flag++;
                }
            }
        }
        for ($i=$flag; $i < count($movie->episodes); $i++) {
            $movie->episodes[$i]->delete();
        }
    }
}
