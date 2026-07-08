<?php

namespace App\Support;

class PublicStorageUrl
{
    public static function from(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }

        return asset('storage/' . $relative);
    }
}
