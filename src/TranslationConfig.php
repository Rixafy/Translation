<?php

declare(strict_types=1);

namespace Rixafy\Translation;

use Rixafy\Language\Exception\LanguageNotFoundException;
use Rixafy\Language\LanguageProvider;

class TranslationConfig
{
	/** @var LanguageProvider */
	private $languageProvider;

	private function __construct(LanguageProvider $languageProvider)
	{
		$this->languageProvider = $languageProvider;
	}

	/**
	 * @throws LanguageNotFoundException
	 */
	public function setCurrentLanguage(string $isoCode): void
	{
		$this->languageProvider->provide($isoCode);
	}
}
