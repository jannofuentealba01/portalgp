<?php
declare(strict_types=1);

if (!function_exists('ctFieldEscape')) {
    function ctFieldEscape(string $value): string
    {
        if (function_exists('ctEscape')) {
            return ctEscape($value);
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ctRenderFieldLabel')) {
    function ctRenderFieldLabel(string $text, bool $required = false, string $optionalText = 'opcional'): void
    {
        ?>
        <label class="form-label ct-label">
            <span><?php echo ctFieldEscape($text); ?></span>
            <?php if ($required): ?>
                <span class="ct-required" aria-hidden="true">*</span>
                <span class="visually-hidden">(Obligatorio)</span>
            <?php else: ?>
                <span class="ct-optional">(<?php echo ctFieldEscape($optionalText); ?>)</span>
            <?php endif; ?>
        </label>
        <?php
    }
}

if (!function_exists('ctRenderFieldHint')) {
    function ctRenderFieldHint(string $text): void
    {
        if (trim($text) === '') {
            return;
        }
        echo '<div class="ct-help">' . ctFieldEscape($text) . '</div>';
    }
}

if (!function_exists('ctRenderFieldError')) {
    function ctRenderFieldError(string $id, string $text): void
    {
        if (trim($id) === '' || trim($text) === '') {
            return;
        }
        echo '<div id="' . ctFieldEscape($id) . '" class="ct-error d-none">' . ctFieldEscape($text) . '</div>';
    }
}
