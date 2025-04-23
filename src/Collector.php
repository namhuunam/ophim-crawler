<?php

namespace Ophim\Crawler\OphimCrawler;

use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManagerStatic as Image;
use Illuminate\Support\Facades\Storage;

class Collector
{
    protected $fields;
    protected $payload;
    protected $forceUpdate;

    public function __construct(array $payload, array $fields, $forceUpdate)
    {
        $this->fields = $fields;
        $this->payload = $payload;
        $this->forceUpdate = $forceUpdate;
    }

    public function get(): array
    {
        $info = $this->payload['movie'] ?? [];
        $episodes = $this->payload['episodes'] ?? [];

        // Xử lý thumbnail và poster với logic mới
        $thumbUrl = $info['thumb_url'];
        $posterUrl = $info['poster_url'];

        // Đầu tiên xử lý thumbnail
        $processedThumbUrl = $this->getThumbImage($info['slug'], $thumbUrl);
        
        // Sau đó xử lý poster
        $processedPosterUrl = $this->getPosterImage($info['slug'], $posterUrl, $processedThumbUrl);

        $data = [
            'name' => $info['name'],
            'origin_name' => $info['origin_name'],
            'publish_year' => $info['year'],
            'content' => $info['content'],
            'type' =>  $this->getMovieType($info, $episodes),
            'status' => $info['status'],
            'thumb_url' => $processedThumbUrl,
            'poster_url' => $processedPosterUrl,
            'is_copyright' => $info['is_copyright'],
            'trailer_url' => $info['trailer_url'] ?? "",
            'quality' => $info['quality'],
            'language' => $info['lang'],
            'episode_time' => $info['time'],
            'episode_current' => $info['episode_current'],
            'episode_total' => $info['episode_total'],
            'notify' => $info['notify'],
            'showtimes' => $info['showtimes'],
            'is_shown_in_theater' => $info['chieurap'],
        ];

        return $data;
    }

    public function getThumbImage($slug, $url)
    {
        return $this->getImage(
            $slug,
            $url,
            Option::get('should_resize_thumb', false),
            Option::get('resize_thumb_width'),
            Option::get('resize_thumb_height'),
            'thumb'
        );
    }

    public function getPosterImage($slug, $url, $processedThumbUrl = null)
    {
        $posterUrl = $this->getImage(
            $slug,
            $url,
            Option::get('should_resize_poster', false),
            Option::get('resize_poster_width'),
            Option::get('resize_poster_height'),
            'poster',
            $processedThumbUrl
        );
        
        // Nếu poster bị lỗi và không tìm được ảnh thay thế, nhưng thumb đã xử lý thành công
        // và có đường dẫn local (không phải URL gốc), thì sử dụng thumb cho poster
        if ($posterUrl === $url && $processedThumbUrl !== null && strpos($processedThumbUrl, '/storage/') === 0) {
            Log::info("Sử dụng ảnh thumb cho poster của $slug: $processedThumbUrl");
            return $processedThumbUrl;
        }
        
        return $posterUrl;
    }

    protected function getMovieType($info, $episodes)
    {
        return $info['type'] == 'series' ? 'series'
            : ($info['type'] == 'single' ? 'single'
                : (count(reset($episodes)['server_data'] ?? []) > 1 ? 'series' : 'single'));
    }

    protected function getImage($slug, string $url, $shouldResize = false, $width = null, $height = null, $imageType = 'unknown', $otherProcessedUrl = null): string
    {
        if (!Option::get('download_image', false) || empty($url)) {
            return $url;
        }
        try {
            $url = strtok($url, '?');
            $filename = substr($url, strrpos($url, '/') + 1);
            
            // Nếu chuyển đổi WebP được bật, đổi phần mở rộng thành webp
            if (Option::get('convert_to_webp', false)) {
                $filenameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);
                $filename = $filenameWithoutExtension . '.webp';
            }
            
            $path = "images/{$slug}/{$filename}";

            if (Storage::disk('public')->exists($path) && $this->forceUpdate == false) {
                return Storage::url($path);
            }

            // Khởi tạo curl để tải về hình ảnh
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36");
            $image_data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            // Kiểm tra xem dữ liệu tải về có lỗi không
            $isError = false;
            
            // Kiểm tra HTTP code
            if ($httpCode !== 200) {
                $isError = true;
                Log::error("Lỗi HTTP khi tải ảnh {$imageType}: $httpCode - $url");
            }
            
            // Kiểm tra xem phản hồi có phải là XML lỗi không
            if (strpos($contentType, 'xml') !== false || 
                strpos($image_data, '<?xml') === 0 || 
                strpos($image_data, '<Error>') !== false) {
                $isError = true;
                Log::error("Lỗi XML khi tải ảnh {$imageType}: $url");
            }
            
            // Thử tải ảnh từ các nguồn khác nếu có lỗi
            if ($isError) {
                Log::info("Đang tìm kiếm ảnh {$imageType} thay thế cho $slug");
                
                // Thử nguồn từ phimapi.com
                $alternativeUrl = $this->getAlternativeImageFromPhimApi($slug, $imageType . '_url');
                
                if (!empty($alternativeUrl)) {
                    Log::info("Đã tìm thấy ảnh {$imageType} thay thế từ phimapi.com: $alternativeUrl");
                    return $this->getImage($slug, $alternativeUrl, $shouldResize, $width, $height, $imageType);
                }
                
                // Thử nguồn từ phim.nguonc.com
                $alternativeUrl = $this->getAlternativeImageFromNguonC($slug, $imageType . '_url');
                
                if (!empty($alternativeUrl)) {
                    Log::info("Đã tìm thấy ảnh {$imageType} thay thế từ phim.nguonc.com: $alternativeUrl");
                    return $this->getImage($slug, $alternativeUrl, $shouldResize, $width, $height, $imageType);
                }
                
                // XỬ LÝ TRƯỜNG HỢP ĐẶC BIỆT
                
                // Nếu là ảnh poster và có đường dẫn thumb đã xử lý thành công (local path)
                if ($imageType === 'poster' && $otherProcessedUrl !== null && strpos($otherProcessedUrl, '/storage/') === 0) {
                    Log::info("Sử dụng ảnh thumb cho poster của $slug: $otherProcessedUrl");
                    return $otherProcessedUrl;
                }
                
                // Nếu là ảnh thumb, không tìm được ảnh thay thế, 
                // giữ nguyên URL gốc và để phần xử lý poster quyết định sau
                if ($imageType === 'thumb') {
                    Log::error("Không tìm thấy ảnh thumb thay thế cho $slug. Giữ nguyên URL gốc: $url");
                    return $url;
                }

                // Trường hợp khác: Không tìm thấy ảnh thay thế, trả về URL gốc
                Log::error("Không tìm thấy ảnh {$imageType} thay thế cho $slug. Giữ nguyên URL gốc: $url");
                return $url;
            }

            // Xử lý ảnh bình thường nếu không có lỗi
            $img = Image::make($image_data);

            if ($shouldResize) {
                $img->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }

            Storage::disk('public')->put($path, null);
            
            // Chuyển đổi sang định dạng WebP nếu tùy chọn được bật
            if (Option::get('convert_to_webp', false)) {
                // Mặc định chất lượng nén WebP là 80%
                $img->encode('webp', 80);
            }

            $img->save(storage_path("app/public/" . $path));

            return Storage::url($path);
        } catch (\Exception $e) {
            Log::error("Lỗi xử lý ảnh {$imageType}: " . $e->getMessage() . " - URL: $url");
            return $url;
        }
    }
    
    /**
     * Tìm URL ảnh thay thế từ phimapi.com
     * 
     * @param string $slug Slug của phim
     * @param string $imageType Loại ảnh (thumb_url hoặc poster_url)
     * @return string|null URL ảnh thay thế hoặc null nếu không tìm thấy
     */
    private function getAlternativeImageFromPhimApi(string $slug, string $imageType): ?string
    {
        try {
            $apiUrl = "https://phimapi.com/phim/{$slug}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36");
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if (!$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['status']) && $data['status'] === true && isset($data['movie'][$imageType])) {
                return $data['movie'][$imageType];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error("Lỗi khi tìm kiếm ảnh từ phimapi.com: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Tìm URL ảnh thay thế từ phim.nguonc.com
     * 
     * @param string $slug Slug của phim
     * @param string $imageType Loại ảnh (thumb_url hoặc poster_url)
     * @return string|null URL ảnh thay thế hoặc null nếu không tìm thấy
     */
    private function getAlternativeImageFromNguonC(string $slug, string $imageType): ?string
    {
        try {
            $apiUrl = "https://phim.nguonc.com/api/film/{$slug}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36");
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if (!$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['status']) && $data['status'] === 'success' && isset($data['movie'][$imageType])) {
                return $data['movie'][$imageType];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error("Lỗi khi tìm kiếm ảnh từ phim.nguonc.com: " . $e->getMessage());
            return null;
        }
    }
}
