<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'location',
        'avatar',
        'google_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'avatar_data',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function scans()
    {
        return $this->hasMany(RiceScan::class);
    }

    public function storeAvatarImage(UploadedFile $image): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new \RuntimeException('Image processing is unavailable on this server.');
        }

        $imageInfo = @getimagesize($image->getRealPath());
        if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new \InvalidArgumentException('The selected profile picture is not a valid image.');
        }

        [$sourceWidth, $sourceHeight] = $imageInfo;
        if ($sourceWidth * $sourceHeight > 25000000) {
            throw new \InvalidArgumentException('Choose a profile picture with fewer than 25 megapixels.');
        }

        $sourceBytes = file_get_contents($image->getRealPath());
        $source = $sourceBytes === false ? false : @imagecreatefromstring($sourceBytes);
        if (!$source) {
            throw new \InvalidArgumentException('The selected profile picture could not be processed.');
        }

        $scale = min(1, 512 / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $thumbnail = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefill($thumbnail, 0, 0, $white);
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        $encoded = imagejpeg($thumbnail, null, 82);
        $jpegBytes = ob_get_clean();
        imagedestroy($thumbnail);
        imagedestroy($source);

        if (!$encoded || !is_string($jpegBytes) || $jpegBytes === '') {
            throw new \RuntimeException('The profile picture could not be saved. Please try another image.');
        }

        // Persist the compact image in the database, which survives app restarts.
        $this->avatar_data = base64_encode($jpegBytes);
        $this->avatar = null;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_data) {
            return route('users.avatar', [
                'user' => $this->getKey(),
                'v' => substr(hash('sha256', $this->avatar_data), 0, 16),
            ]);
        }

        if (!$this->avatar) {
            return null;
        }
        if (str_starts_with($this->avatar, 'http://') || str_starts_with($this->avatar, 'https://') || str_starts_with($this->avatar, 'data:')) {
            return $this->avatar;
        }
        return asset('storage/' . $this->avatar);
    }
}
