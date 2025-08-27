<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TranslationInterface;
use Knp\DoctrineBehaviors\Model\Translatable\TranslationTrait;

#[ORM\Entity]
#[ORM\Table(name: 'media_translation')]
class MediaTranslation implements TranslationInterface
{
    use TranslationTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public readonly ?int $id;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $caption;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $altText;
}
