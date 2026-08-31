<?php
declare(strict_types=1);

if (!function_exists('ctMontoClpEscape')) {
    function ctMontoClpEscape(string $value): string
    {
        if (function_exists('ctEscape')) {
            /** @var callable $esc */
            $esc = 'ctEscape';
            return (string) $esc($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ctMontoClpFormatValue')) {
    function ctMontoClpFormatValue(string $value, int $decimals = 2): string
    {
        $raw = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($value));
        $raw = is_string($raw) ? $raw : '';
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
        }

        if (!is_numeric($raw)) {
            return $value;
        }

        return number_format((float) $raw, $decimals, ',', '.');
    }
}

if (!function_exists('ctRenderMontoClpAssets')) {
    function ctRenderMontoClpAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <style>
            .ct-monto-label {
                margin-bottom: .25rem;
                font-size: .78rem;
                font-weight: 700;
                color: #15803d;
            }

            .ct-monto-label i {
                margin-right: .25rem;
            }

            .ct-monto-group .input-group-text {
                background: #f0fdf4;
                border-color: #16a34a;
                color: #15803d;
                font-weight: 700;
            }

            .ct-monto-input {
                font-size: 1.25rem;
                font-weight: 700;
                border-color: #16a34a;
                box-shadow: 0 0 0 1px #bbf7d0;
                color: #15803d;
            }
        </style>
        <script>
        (() => {
            const bindMontoClpInputs = () => {
                const montoInputs = Array.from(document.querySelectorAll('.js-ct-monto-clp')).filter((el) => el instanceof HTMLInputElement);
                if (montoInputs.length === 0) {
                    return;
                }

                const sanitizeMontoInput = (event) => {
                    const input = event.target;
                    if (!(input instanceof HTMLInputElement)) {
                        return;
                    }
                    const oldValue = input.value;
                    const selectionStart = input.selectionStart ?? oldValue.length;
                    const charsBeforeCursor = oldValue.slice(0, selectionStart);

                    const sanitizeRaw = (value) => {
                        let out = value.replace(/[^\d,]/g, '');
                        const commaIndex = out.indexOf(',');
                        if (commaIndex !== -1) {
                            const decimals = out.slice(commaIndex + 1).replace(/,/g, '').slice(0, 2);
                            out = out.slice(0, commaIndex + 1) + decimals;
                        }
                        return out;
                    };

                    const raw = sanitizeRaw(oldValue);
                    const rawBeforeCursor = sanitizeRaw(charsBeforeCursor);

                    if (raw === oldValue) {
                        return;
                    }

                    input.value = raw;
                    const newPos = Math.min(rawBeforeCursor.length, raw.length);
                    input.setSelectionRange(newPos, newPos);
                };

                const formatMontoOnBlur = (event) => {
                    const input = event.target;
                    if (!(input instanceof HTMLInputElement)) {
                        return;
                    }
                    const raw = input.value.trim();
                    if (raw === '') {
                        return;
                    }
                    const normalized = raw.replace(/\./g, '').replace(',', '.');
                    const n = Number.parseFloat(normalized);
                    if (!Number.isFinite(n)) {
                        return;
                    }
                    input.value = n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                };

                montoInputs.forEach((input) => {
                    if (input.dataset.montoClpBound === '1') {
                        return;
                    }
                    input.dataset.montoClpBound = '1';
                    input.addEventListener('input', sanitizeMontoInput);
                    input.addEventListener('blur', formatMontoOnBlur);
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bindMontoClpInputs);
            } else {
                bindMontoClpInputs();
            }
        })();
        </script>
        <?php
    }
}

if (!function_exists('ctRenderMontoClpField')) {
    /**
     * @param array{
     *   wrapper_class?: string,
     *   id?: string,
     *   name?: string,
     *   label?: string,
     *   value?: string,
     *   placeholder?: string,
     *   required?: bool,
     *   hint?: string,
     *   autocomplete?: string,
     *   format_initial?: bool,
     *   decimals?: int
     * } $options
     */
    function ctRenderMontoClpField(array $options = []): void
    {
        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'col-12 col-lg-3'));
        $id = trim((string) ($options['id'] ?? 'monto_input'));
        $name = trim((string) ($options['name'] ?? 'monto'));
        $label = trim((string) ($options['label'] ?? 'Monto'));
        $value = (string) ($options['value'] ?? '');
        $placeholder = trim((string) ($options['placeholder'] ?? '0,00'));
        $required = (bool) ($options['required'] ?? true);
        $hint = trim((string) ($options['hint'] ?? ''));
        $autocomplete = trim((string) ($options['autocomplete'] ?? 'off'));
        $formatInitial = (bool) ($options['format_initial'] ?? true);
        $decimals = (int) ($options['decimals'] ?? 2);
        if ($decimals < 0 || $decimals > 6) {
            $decimals = 2;
        }
        $renderValue = $formatInitial ? ctMontoClpFormatValue($value, $decimals) : $value;
        ?>
        <div class="<?php echo ctMontoClpEscape($wrapperClass); ?>">
            <label class="form-label ct-monto-label" for="<?php echo ctMontoClpEscape($id); ?>">
                <i class="bi bi-cash-coin" aria-hidden="true"></i><?php echo ctMontoClpEscape($label); ?>
            </label>
            <div class="input-group ct-monto-group">
                <span class="input-group-text">$</span>
                <input
                    type="text"
                    class="form-control ct-monto-input js-ct-monto-clp"
                    id="<?php echo ctMontoClpEscape($id); ?>"
                    name="<?php echo ctMontoClpEscape($name); ?>"
                    value="<?php echo ctMontoClpEscape($renderValue); ?>"
                    placeholder="<?php echo ctMontoClpEscape($placeholder); ?>"
                    inputmode="decimal"
                    autocomplete="<?php echo ctMontoClpEscape($autocomplete); ?>"
                    <?php echo $required ? 'required' : ''; ?>>
            </div>
            <?php if ($hint !== ''): ?>
                <div class="text-muted small mt-1"><?php echo ctMontoClpEscape($hint); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
