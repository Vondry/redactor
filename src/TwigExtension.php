<?php

declare(strict_types=1);

namespace Bolt\Redactor;

use Bolt\Common\Json;
use Bolt\Configuration\Config;
use Symfony\Component\Filesystem\Path;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RedactorConfig $redactorConfig,
        private readonly Config $boltConfig,
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

        // Then the UI language file matching the resolved locale (see
        // RedactorConfig::resolveLocale), so the toolbar is localized without the
        // user having to add it to `includes` manually. English is built into
        // redactor.min.js, and unsupported locales are skipped (no such file), in
        // which case Redactor falls back to English on its own.
        $output .= $this->redactorLangInclude();

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
     * A `<script>` tag for the Redactor UI language file matching the configured
     * locale, or an empty string when it isn't needed/available (English, or a
     * locale Redactor ships no translation for).
     */
    private function redactorLangInclude(): string
    {
        $lang = $this->redactorConfig->getConfig()['lang'] ?? 'en';

        if (! is_string($lang) || $lang === '' || $lang === 'en') {
            return '';
        }

        $relative = sprintf('/assets/redactor/langs/%s.js', $lang);
        $absolute = $this->projectDir . '/' . $this->publicFolder . $relative;

        if (! is_file($absolute)) {
            return '';
        }

        return sprintf('<script src="%s"></script>', $relative) . "\n";
    }

    private function makePath(string $item): string
    {
        $path = $this->boltConfig->getPath($item, false);
        $publicFolder = $this->projectDir . '/' . $this->publicFolder;

        $path = '/' . Path::makeRelative($path, $publicFolder);

        return $path;
    }
}
