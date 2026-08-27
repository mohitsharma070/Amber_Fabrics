<?php

if (!function_exists('migration_file_checksum')) {
    /**
     * Hash migration SQL using repository-canonical LF line endings so the
     * checksum is stable across Windows and Unix checkouts.
     *
     * @return string|false
     */
    function migration_file_checksum(string $path)
    {
        $source = file_get_contents($path);
        if ($source === false) {
            return false;
        }

        $canonicalSource = str_replace(["\r\n", "\r"], "\n", $source);
        return hash('sha256', $canonicalSource);
    }
}

if (!function_exists('migration_file_checksum_matches')) {
    /**
     * Accept the canonical LF checksum and the legacy CRLF checksum previously
     * recorded by Windows checkouts. No other content differences are allowed.
     */
    function migration_file_checksum_matches(string $path, string $storedChecksum): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $storedChecksum)) {
            return false;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            return false;
        }

        $canonicalSource = str_replace(["\r\n", "\r"], "\n", $source);
        $acceptedChecksums = [
            hash('sha256', $canonicalSource),
            hash('sha256', str_replace("\n", "\r\n", $canonicalSource)),
        ];
        foreach (array_unique($acceptedChecksums) as $acceptedChecksum) {
            if (hash_equals($acceptedChecksum, $storedChecksum)) {
                return true;
            }
        }
        return false;
    }
}
