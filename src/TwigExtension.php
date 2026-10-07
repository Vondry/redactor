<?php

declare(strict_types=1);

namespace Bolt\Redactor;

use Bolt\Common\Json;
use Bolt\Configuration\Config;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    private const LANGS_DIR = 'assets/redactor/langs';

    public function __construct(
        private readonly RedactorConfig $redactorConfig,
        private readonly Config $boltConfig,
        private readonly RequestStack $requestStack,
        private readonly string $projectDir,
        private readonly string $publicFolder,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = [
            'is_safe' => ['html'],
        ];

        return [
            new TwigFunction('redactor_settings', $this->redactorSettings(...), $safe),
            new TwigFunction('redactor_includes', $this->redactorIncludes(...), $safe),
        ];
    }

    public function redactorSettings(): string
    {
        $settings = $this->redactorConfig->getConfig();

        // The editor UI language always follows the current Bolt backend locale
        // (resolved per user by Bolt's LocaleSubscriber). It is intentionally not
        // configurable — set last so any stray `lang:` in config can't freeze it.
        // The matching langs/<code>.js is loaded by redactor_includes().
        $settings['lang'] = $this->resolveLocale();

        return Json::json_encode($settings, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_PRETTY_PRINT);
    }

    public function redactorIncludes(): string
    {
        // First, the includes needed for the various activated plugins
        $used = $this->redactorConfig->getConfig()['plugins'];
        $plugins = collect($this->redactorConfig->getPlugins());

        $output = '';

        foreach ($used as $item) {
            if (! is_string($item) || ! $plugins->get($item)) {
                continue;
            }

            foreach ($plugins->get($item) as $file) {
                if (Path::getExtension($file) === 'css') {
                    $output .= sprintf('<link rel="stylesheet" href="/assets/redactor/plugins/%s">', $file);
                }
                if (Path::getExtension($file) === 'js') {
                    $output .= sprintf('<script src="/assets/redactor/plugins/%s"></script>', $file);
                }
                $output .= "\n";
            }
        }

        // Then the UI language file for the resolved locale (see resolveLocale()),
        // so the toolbar is localized without the user having to add it to
        // `includes` manually. Exactly one file is loaded. When the locale resolves
        // to English (including the fallback), that file is langs/en.js, which
        // replaces redactor.min.js' built-in `en` table: the built-in one lacks keys
        // we use, such as the `small` format label.
        $output .= sprintf('<script src="%s"></script>', $this->langFilePath($this->resolveLocale()));

        // Next, if there are extra inludes configured, we add them here
        $includes = $this->redactorConfig->getConfig()['includes'];

        foreach ($includes as $item) {
            $item = $this->makePath($item);

            if (Path::getExtension($item) === 'css') {
                $output .= sprintf('<link rel="stylesheet" href="%s">', $item);
            }
            if (Path::getExtension($item) === 'js') {
                $output .= sprintf('<script src="%s"></script>', $item);
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * The locale to use for the editor UI. Uses the current request locale, which
     * Bolt resolves per user in the backend (LocaleSubscriber sets it from the
     * user's `_backend_locale`).
     *
     * Bolt locales look like `pt_BR` / `zh-CN`, while the shipped language files
     * (and the `$R.lang[...]` keys inside them) are lowercase with an underscore,
     * e.g. `pt_br`. So the locale is normalized first, then matched exactly, then
     * by its bare language code (`de_AT` -> `de`).
     *
     * Falls back to English when there is no request (e.g. CLI / cache warmup) or
     * when we ship no matching langs/<code>.js. This is the only file existence
     * check: the result always names a shipped file, so redactor_includes() can
     * load it as is, and `lang` in the settings names the table it loaded.
     */
    private function resolveLocale(): string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? '';
        $locale = mb_strtolower(str_replace('-', '_', $locale));

        foreach ([$locale, mb_strstr($locale, '_', true)] as $candidate) {
            if (is_string($candidate) && $this->hasLangFile($candidate)) {
                return $candidate;
            }
        }

        return 'en';
    }

    private function hasLangFile(string $locale): bool
    {
        $langsDir = Path::join($this->projectDir, $this->publicFolder, self::LANGS_DIR);
        $file = Path::join($langsDir, $locale . '.js');

        // The locale comes from the request, and Bolt accepts any `?_locale=` value. So it
        // must be a plain file name (no path segments) that stays inside the langs directory.
        return Path::isBasePath($langsDir, $file)
            && Path::getFilenameWithoutExtension($file, '.js') === $locale
            && is_file($file);
    }

    private function langFilePath(string $locale): string
    {
        return '/' . Path::join(self::LANGS_DIR, $locale . '.js');
    }

    private function makePath(string $item): string
    {
        $path = $this->boltConfig->getPath($item, false);
        $publicFolder = $this->projectDir . '/' . $this->publicFolder;

        $path = '/' . Path::makeRelative($path, $publicFolder);

        return $path;
    }
}
