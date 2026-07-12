<?php
declare(strict_types=1);

/**
 * Converts the legacy bilingual public HTML into a Russian-only response.
 * Visitors and search engines receive no hidden English copy or language controls.
 */
function mgw_russian_only(string $html): string
{
    if ($html === '') return $html;

    $html = preg_replace_callback('~<script\b[^>]*>.*?</script>~is', static function (array $match): string {
        $script = $match[0];
        return stripos($script, 'setLang(') !== false || stripos($script, 'mgw_lang') !== false || stripos($script, 'data-lang-btn') !== false ? '' : $script;
    }, $html) ?? $html;

    $protected = [];
    $html = preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>~is', static function (array $match) use (&$protected): string {
        $key = '___MGW_PROTECTED_' . count($protected) . '___';
        $protected[$key] = $match[0];
        return $key;
    }, $html) ?? $html;

    $isClass = static function (string $tag, string $class): bool {
        if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $tag, $match) !== 1) return false;
        $classes = preg_split('~\s+~', trim($match[2])) ?: [];
        return in_array($class, $classes, true);
    };
    $hasDataLang = static fn(string $tag, string $lang): bool => preg_match('~\bdata-lang\s*=\s*(["\'])' . preg_quote($lang, '~') . '\1~i', $tag) === 1;

    preg_match_all('~<!--.*?-->|<!DOCTYPE[^>]*>|<\?[^>]*\?>|</?[A-Za-z][^>]*>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
    $output = '';
    $cursor = 0;
    $stack = [];
    $removeDepth = 0;
    $voidTags = array_fill_keys(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'], true);

    foreach ($tokens[0] as [$tag, $offset]) {
        if ($offset > $cursor && $removeDepth === 0) $output .= substr($html, $cursor, $offset - $cursor);
        $cursor = $offset + strlen($tag);

        if (str_starts_with($tag, '<!--') || str_starts_with(strtoupper($tag), '<!DOCTYPE') || str_starts_with($tag, '<?')) {
            if ($removeDepth === 0) $output .= $tag;
            continue;
        }

        if (preg_match('~^</\s*([A-Za-z0-9:-]+)~', $tag, $nameMatch) === 1) {
            $frame = array_pop($stack);
            if ($frame === null) {
                if ($removeDepth === 0) $output .= $tag;
                continue;
            }
            if ($frame['action'] === 'remove') $removeDepth = max(0, $removeDepth - 1);
            elseif ($frame['action'] === 'keep' && $removeDepth === 0) $output .= $tag;
            continue;
        }

        if (preg_match('~^<\s*([A-Za-z0-9:-]+)~', $tag, $nameMatch) !== 1) {
            if ($removeDepth === 0) $output .= $tag;
            continue;
        }

        $name = strtolower($nameMatch[1]);
        $selfClosing = str_ends_with(rtrim($tag), '/>') || isset($voidTags[$name]);
        $remove = $removeDepth > 0 || (($name !== 'html' && $name !== 'body') && $hasDataLang($tag, 'en')) || $isClass($tag, 'en') || $isClass($tag, 'siteLang') || $isClass($tag, 'mobileLang') || $isClass($tag, 'lang') || stripos($tag, 'data-lang-btn') !== false;
        $unwrap = !$remove && ($name !== 'html' && $name !== 'body') && ($hasDataLang($tag, 'ru') || $isClass($tag, 'ru'));
        $action = $remove ? 'remove' : ($unwrap ? 'unwrap' : 'keep');

        if (!$selfClosing) {
            $stack[] = ['name' => $name, 'action' => $action];
            if ($action === 'remove') $removeDepth++;
        }
        if ($action === 'keep' && $removeDepth === 0) $output .= $tag;
    }
    if ($cursor < strlen($html) && $removeDepth === 0) $output .= substr($html, $cursor);
    foreach ($protected as $key => $value) $output = str_replace($key, $value, $output);

    $output = preg_replace_callback('~<html\b([^>]*)>~i', static function (array $match): string {
        $attrs = preg_replace('~\s(?:lang|data-lang)\s*=\s*(["\']).*?\1~i', '', $match[1]) ?? $match[1];
        return '<html lang="ru"' . $attrs . '>';
    }, $output, 1) ?? $output;
    $output = preg_replace('~\sdata-[a-z0-9_-]*-en\s*=\s*(["\']).*?\1~i', '', $output) ?? $output;
    $output = preg_replace('~\sdata-(?:description|title)-en\s*=\s*(["\']).*?\1~i', '', $output) ?? $output;
    $output = preg_replace('~\sdata-lang\s*=\s*(["\']).*?\1~i', '', $output) ?? $output;

    $output = preg_replace_callback('~<title([^>]*)\sdata-title-ru\s*=\s*(["\'])(.*?)\2([^>]*)>.*?</title>~is', static function (array $match): string {
        $attrs = trim(($match[1] ?? '') . ' ' . ($match[4] ?? ''));
        return '<title' . ($attrs !== '' ? ' ' . $attrs : '') . '>' . htmlspecialchars_decode($match[3], ENT_QUOTES) . '</title>';
    }, $output, 1) ?? $output;
    $output = preg_replace_callback('~<meta([^>]*)\sdata-description-ru\s*=\s*(["\'])(.*?)\2([^>]*)>~is', static function (array $match): string {
        $attrs = trim(($match[1] ?? '') . ' ' . ($match[4] ?? ''));
        $attrs = preg_replace('~\scontent\s*=\s*(["\']).*?\1~i', '', $attrs) ?? $attrs;
        return '<meta ' . trim($attrs) . ' content="' . htmlspecialchars(htmlspecialchars_decode($match[3], ENT_QUOTES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }, $output, 1) ?? $output;
    $output = preg_replace('~\sdata-(?:title|description)-ru\s*=\s*(["\']).*?\1~i', '', $output) ?? $output;

    $output = preg_replace_callback('~<body\b([^>]*)>~i', static function (array $match): string {
        $attrs = preg_replace_callback('~\sclass\s*=\s*(["\'])(.*?)\1~i', static function (array $classMatch): string {
            $classes = preg_split('~\s+~', trim($classMatch[2])) ?: [];
            $classes = array_values(array_filter($classes, static fn(string $class): bool => !in_array($class, ['lang-ru', 'lang-en'], true)));
            return $classes ? ' class="' . implode(' ', $classes) . '"' : '';
        }, $match[1]) ?? $match[1];
        return '<body' . $attrs . '>';
    }, $output, 1) ?? $output;

    // The language setting no longer exists, so it must not remain in the cookie policy.
    $output = preg_replace('~<li>\s*<strong>mgw_lang</strong>.*?</li>~is', '', $output) ?? $output;

    $output = strtr($output, [
        '>Privacy<' => '>Конфиденциальность<',
        '>Privacy Policy<' => '>Политика конфиденциальности<',
        '>Terms<' => '>Условия использования<',
        '>Terms of Use<' => '>Условия использования<',
        '>Cookie Policy<' => '>Политика cookies<',
        '>Cookie settings<' => '>Настройки cookies<',
        '>Cookies<' => '>Файлы cookies<',
        '>Home<' => '>Главная<',
        '>GUIDE<' => '>РУКОВОДСТВО<',
        '>Guide<' => '>Руководство<',
        '>STRATEGY<' => '>СТРАТЕГИЯ<',
        '>MINI APPS<' => '>MINI APP<',
        '>STATUS<' => '>СТАТУС<',
        '>FEATURE STATUS<' => '>СТАТУС ФУНКЦИИ<',
        '>ROADMAP<' => '>ПЛАНЫ<',
        '>GOLD ROOM<' => '>КОМНАТА GOLD<',
        '>Gold Room<' => '>Комната Gold<',
        '>HOW TO PLAY<' => '>КАК ИГРАТЬ<',
        '>EXPLAINER<' => '>ОБЗОР<',
        '>Friends<' => '>Друзья<',
        '>Tic-tac-toe<' => '>Крестики-нолики<',
        ' min</span>' => ' мин</span>',
        'языковые и cookie-настройки' => 'настройки cookies',
        'language and cookie preferences' => 'настройки cookies',
        'После удаления язык вернётся к значению по умолчанию, а информационное окно появится снова.' => 'После удаления информационное окно появится снова.',
        'Сайт сохраняет только необходимые настройки языка и ваш выбор в этом окне.' => 'Сайт сохраняет только ваш выбор в этом информационном окне.',
        'Только необходимые' => 'Закрыть',
        'Принять' => 'Понятно',
        '/ Tic-tac-toe</div>' => '/ Крестики-нолики</div>',
        '/ Friends</div>' => '/ Друзья</div>',
    ]);

    return $output;
}
