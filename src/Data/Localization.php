<?php

namespace Native\Mobile\Data;

class Localization
{
    public function __construct(
        public readonly string $locale,
        public readonly string $languageCode,
        public readonly string $regionCode,
        public readonly string $timezone,
        public readonly string $currencyCode,
        public readonly string $preferredLanguage,
    ) {}
}
