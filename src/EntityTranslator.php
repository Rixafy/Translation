<?php

declare(strict_types=1);

namespace Rixafy\Translation;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use ReflectionClass;
use ReflectionException;
use Rixafy\Language\Exception\LanguageNotProvidedException;
use Rixafy\Language\Language;
use Rixafy\Language\LanguageStaticHolder;
use Rixafy\Translation\Exception\TranslationNotFoundException;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class EntityTranslator
{
	#[ORM\ManyToOne(targetEntity: Language::class, inversedBy: 'entity')]
    protected ?Language $fallbackLanguage = null;

    /** @var object */
    protected $translation;

    /** @var ArrayCollection */
    protected $translations;

    /** @var Language */
    protected $translationLanguage;

    /**
     * @throws LanguageNotProvidedException
     */
	#[ORM\PostLoad]
    public function injectDefaultTranslation(): void
    {
        $language = LanguageStaticHolder::getLanguage();

        if ($this->translation === null) {
            $criteria = Criteria::create()
                ->where(Criteria::expr()->eq('language', $language))
                ->setMaxResults(1);

            $this->translation = $this->translations->matching($criteria)->first();
            $this->translationLanguage = $language;

            if (!$this->translation) {
                $criteria = Criteria::create()
                    ->where(Criteria::expr()->eq('language', $this->fallbackLanguage))
                    ->setMaxResults(1);

                $this->translation = $this->translations->matching($criteria)->first();
                $this->translationLanguage = $this->fallbackLanguage;
            }
        }

        try {
            $this->injectFields();
        } catch (ReflectionException | TranslationNotFoundException $e) {
        }
    }

    /**
     * @throws ReflectionException
     * @throws TranslationNotFoundException
     */
    protected function injectFields(): void
    {
        if ($this->translation == null) {
            throw new TranslationNotFoundException('Translation for ' . get_class($this) . ' not found');
        }

        $reflection = new ReflectionClass($this->translation);

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            if ($propertyName === 'id' || $propertyName == 'language' || $propertyName == 'entity') {
                continue;
            }

            $property->setAccessible(true);
            $this->{$propertyName} = $property->getValue($this->translation);
        }
    }

    public function addTranslation(object $dataObject, Language $language)
    {
		$thisClassName = get_class($this);
		$parentClassName = get_parent_class($this);
		if (__CLASS__ !== $parentClassName) {
			$thisClassName = $parentClassName;
		}

		$class = $thisClassName . 'Translation';
		$translation = new $class($dataObject, $language, $this);

        $this->translations->add($translation);

        if ($this->fallbackLanguage === null) {
            $this->fallbackLanguage = $language;
        }

        return $translation;
    }

    public function editTranslation(object $dataObject, Language $language = null)
    {
        if ($language === null && isset($dataObject->language)) {
            $language = $dataObject->language;

        } elseif ($language === null) {
            return null;
        }

        if ($this->translation !== null && $language === $this->translationLanguage) {
            $this->updateTranslationFields($dataObject, $this->translation);
            try {
                $this->injectFields();

            } catch (ReflectionException | TranslationNotFoundException $ignored) {
            }

        } else {
            if ($this->fallbackLanguage === null) {
                $this->fallbackLanguage = $language;
                $this->translation = $this->addTranslation($dataObject, $language);
                $this->translationLanguage = $language;
                try {
                    $this->injectFields();

                } catch (ReflectionException | TranslationNotFoundException $ignored) {
                }

            } else {
                $translation = $this->getTranslation($language);

                if ($translation === null) {
                    $translation = $this->addTranslation($dataObject, $language);

                    try {
                        if ($language === LanguageStaticHolder::getLanguage()) {
                            $this->translation = $translation;
                            $this->translationLanguage = $language;
                            try {
                                $this->injectFields();

                            } catch (ReflectionException | TranslationNotFoundException $ignored) {
                            }
                        }
                    } catch (LanguageNotProvidedException $ignored) {
                    }

                } else {
                    $this->updateTranslationFields($dataObject, $translation);
                }

                return $translation;
            }
        }

        return $this->translation;
    }

    public function getTranslation(Language $language) {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('language', $language))
            ->setMaxResults(1);

		return ($tmp = $this->translations->matching($criteria)->first()) === false ? null : $tmp;
    }

    private function updateTranslationFields(object $dataObject, object $translation): void
    {
        if (method_exists($translation, 'edit')) {
            $translation->edit($dataObject);

        } else {
            try {
                $reflection = new ReflectionClass($translation);

                foreach ($reflection->getProperties() as $property) {
                    $propertyName = $property->getName();
                    if ($propertyName == 'id' || $propertyName == 'language' || $propertyName == 'entity') {
                        continue;
                    }

					$value = $dataObject->{$propertyName};
					$property->setAccessible(true);
					$property->setValue($translation, $value);
                }

            } catch (ReflectionException $ignored) {
            }
        }
    }
}
