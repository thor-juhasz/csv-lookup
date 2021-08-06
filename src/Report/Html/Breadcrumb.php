<?php declare(strict_types=1);
/*
 * This file is part of csv-lookup.
 *
 * (c) Thor Juhasz <thor@juhasz.pro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CsvLookup\Report\Html;

class Breadcrumb
{
    public function __construct(
        private bool $active,
        private string $name,
        private string $link,
    ) {}

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLink(): string
    {
        return $this->link;
    }
}
