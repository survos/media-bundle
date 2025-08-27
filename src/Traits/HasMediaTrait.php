<?php

namespace Survos\MediaBundle\Traits;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\MediaBundle\Entity\BaseMedia;

trait HasMediaTrait
{
    #[ORM\Column(type: 'json')]
    public array $imageCodes = [];

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $audioCode;

    #[ORM\Column(type: 'json')]
    public array $videoCodes = [];

    // These will be populated by the PostLoadListener
    public Collection $images;
    public ?BaseMedia $audio = null;
    public Collection $videos;

    public function addImageCode(string $code): static
    {
        if (!in_array($code, $this->imageCodes)) {
            $this->imageCodes[] = $code;
        }
        return $this;
    }

    public function removeImageCode(string $code): static
    {
        $this->imageCodes = array_values(array_filter($this->imageCodes, fn($c) => $c !== $code));
        return $this;
    }

    public function addVideoCode(string $code): static
    {
        if (!in_array($code, $this->videoCodes)) {
            $this->videoCodes[] = $code;
        }
        return $this;
    }

    public function removeVideoCode(string $code): static
    {
        $this->videoCodes = array_values(array_filter($this->videoCodes, fn($c) => $c !== $code));
        return $this;
    }
}
