<?php
declare(strict_types=1);

/**
 * Small server-side allowlist sanitizer for admin-authored rich text.
 * It intentionally supports formatting/content tags only; scripts, forms,
 * embeds, event handlers and unsafe URL schemes are discarded.
 */
function sanitize_rich_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Remove comments and dangerous elements together with their contents.
    $html = preg_replace('~<!--[\s\S]*?-->~', '', $html) ?? '';
    $html = preg_replace('~<!doctype[^>]*>~i', '', $html) ?? '';
    $html = preg_replace(
        '~<(script|style|iframe|object|embed|svg|math|template|form|input|button|textarea|select|option|video|audio|source|link|meta|base)\b[^>]*>[\s\S]*?</\1\s*>~i',
        '',
        $html
    ) ?? '';
    $html = preg_replace('~</?(script|style|iframe|object|embed|svg|math|template|form|input|button|textarea|select|option|video|audio|source|link|meta|base)\b[^>]*>~i', '', $html) ?? '';

    $allowedTags = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'blockquote',
        'a', 'img', 'span', 'div', 'pre', 'code', 'sub', 'sup'
    ];
    $voidTags = ['br', 'hr', 'img'];

    $clean = preg_replace_callback(
        '~<\s*(/?)\s*([a-z][a-z0-9]*)\b([^>]*)>~i',
        static function (array $match) use ($allowedTags, $voidTags): string {
            $closing = $match[1] === '/';
            $tag = strtolower($match[2]);
            $rawAttrs = $match[3] ?? '';

            if (!in_array($tag, $allowedTags, true)) {
                return '';
            }
            if ($closing) {
                return in_array($tag, $voidTags, true) ? '' : '</' . $tag . '>';
            }

            $attrs = [];
            if (preg_match_all('/([a-zA-Z_:][a-zA-Z0-9:._-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'`=<>]+))/', $rawAttrs, $attrMatches, PREG_SET_ORDER)) {
                foreach ($attrMatches as $attr) {
                    $name = strtolower($attr[1]);
                    $value = html_entity_decode($attr[2] !== '' ? $attr[2] : ($attr[3] !== '' ? $attr[3] : ($attr[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    if ($name === 'style') {
                        $style = sanitize_rich_style($value);
                        if ($style !== '') $attrs['style'] = $style;
                    } elseif ($name === 'href' && $tag === 'a') {
                        $url = safe_rich_content_url($value);
                        if ($url !== '') $attrs['href'] = $url;
                    } elseif ($name === 'src' && $tag === 'img') {
                        $url = safe_rich_content_url($value);
                        if ($url !== '') $attrs['src'] = $url;
                    } elseif (in_array($name, ['alt', 'title'], true) && in_array($tag, ['a', 'img'], true)) {
                        $attrs[$name] = mb_substr($value, 0, 500);
                    } elseif (in_array($name, ['width', 'height'], true) && $tag === 'img' && preg_match('/^[1-9][0-9]{0,3}$/', $value)) {
                        $attrs[$name] = $value;
                    } elseif ($name === 'target' && $tag === 'a' && in_array($value, ['_blank', '_self'], true)) {
                        $attrs['target'] = $value;
                    } elseif ($name === 'dir' && in_array($value, ['ltr', 'rtl', 'auto'], true)) {
                        $attrs['dir'] = $value;
                    } elseif ($name === 'lang' && preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/', $value)) {
                        $attrs['lang'] = $value;
                    }
                }
            }

            if ($tag === 'a' && isset($attrs['href']) && ($attrs['target'] ?? '') === '_blank') {
                $attrs['rel'] = 'noopener noreferrer';
            }
            if ($tag === 'img' && !isset($attrs['src'])) {
                return '';
            }

            $serialized = '';
            foreach ($attrs as $name => $value) {
                $serialized .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
            return '<' . $tag . $serialized . (in_array($tag, $voidTags, true) ? '>' : '>');
        },
        $html
    );

    return is_string($clean) ? $clean : '';
}

function safe_rich_content_url(string $value): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/[\x00-\x20\x7F]/', $value) || str_contains($value, '\\')) return '';
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return $value;
    if (str_starts_with($value, '#')) return $value;
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $value : '';
}

function sanitize_rich_style(string $style): string
{
    $allowed = [];
    foreach (explode(';', $style) as $declaration) {
        if (!str_contains($declaration, ':')) continue;
        [$property, $value] = array_map('trim', explode(':', $declaration, 2));
        $property = strtolower($property);
        $value = strtolower($value);
        if ($value === '' || preg_match('/url\s*\(|expression|javascript|behavior|@import|\\/\\*/i', $value)) continue;

        if ($property === 'font-size' && preg_match('/^(?:[8-9]|[1-7][0-9]|8[0-4])(?:px|pt|em|rem|%)$/', $value)) {
            $allowed[$property] = $value;
        } elseif (in_array($property, ['color', 'background-color'], true)
            && (preg_match('/^#[0-9a-f]{3,8}$/i', $value) || in_array($value, ['black','white','red','blue','green','gray','grey','orange','purple','transparent'], true))) {
            $allowed[$property] = $value;
        } elseif ($property === 'text-align' && in_array($value, ['left','right','center','justify','start','end'], true)) {
            $allowed[$property] = $value;
        } elseif ($property === 'font-weight' && in_array($value, ['normal','bold','400','500','600','700','800'], true)) {
            $allowed[$property] = $value;
        } elseif ($property === 'font-style' && in_array($value, ['normal','italic'], true)) {
            $allowed[$property] = $value;
        } elseif ($property === 'text-decoration' && in_array($value, ['none','underline','line-through'], true)) {
            $allowed[$property] = $value;
        }
    }
    $parts = [];
    foreach ($allowed as $key => $value) $parts[] = $key . ':' . $value;
    return implode(';', $parts);
}

function rich_html_plain_text(string $html, int $limit = 500): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return mb_substr($text, 0, $limit);
}
