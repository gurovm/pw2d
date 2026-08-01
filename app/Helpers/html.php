<?php

if (!function_exists('sanitize_ai_html')) {
    /**
     * Sanitize AI-generated HTML before a raw `{!! !!}` Blade render.
     *
     * Strips to an allowlist of basic formatting/link tags and removes
     * `javascript:` hrefs — the same convention already used for category
     * buying-guide content in `compare-content.blade.php`. Any AI-generated
     * prose rendered raw (landing-page intro/pick body/FAQ answers, Spec 027
     * review S3) MUST go through this before `{!! !!}`.
     */
    function sanitize_ai_html(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        return preg_replace(
            '/href\s*=\s*["\']javascript:[^"\']*["\']/i',
            '',
            strip_tags($html, '<p><br><ul><ol><li><strong><em><h3><h4><a>')
        );
    }
}
