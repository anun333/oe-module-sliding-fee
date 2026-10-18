<?php

/**
 * The HHS poverty guidelines, from data/poverty-guidelines.json: a household's guideline
 * is the first person's figure plus the figure for each additional person. A year and
 * region whose figures are null isn't loaded, and nothing is estimated for it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\SlidingFee\Domain;

final class PovertyGuidelines
{
    public const REGIONS = ['contiguous', 'alaska', 'hawaii'];

    /**
     * @param array<string, array{firstPerson: int, eachAdditional: int, source: string}> $byKey "year:region"
     */
    private function __construct(private readonly array $byKey)
    {
    }

    public static function fromFile(string $file): self
    {
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['guidelines'] ?? null)) {
            throw new \RuntimeException("The poverty guidelines file $file can't be read");
        }
        return self::fromRows($data['guidelines']);
    }

    /**
     * @param array<mixed> $rows
     */
    public static function fromRows(array $rows): self
    {
        $byKey = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_int($row['year'] ?? null) || !in_array($row['region'] ?? null, self::REGIONS, true)) {
                throw new \RuntimeException('A poverty guidelines row needs a year and a region (contiguous, alaska or hawaii)');
            }
            if (is_int($row['firstPerson'] ?? null) && is_int($row['eachAdditional'] ?? null)) {
                $byKey[$row['year'] . ':' . $row['region']] = [
                    'firstPerson' => $row['firstPerson'],
                    'eachAdditional' => $row['eachAdditional'],
                    'source' => (string)($row['source'] ?? ''),
                ];
            }
        }
        return new self($byKey);
    }

    public function has(int $year, string $region): bool
    {
        return isset($this->byKey["$year:$region"]);
    }

    /** The guideline for a household, or null when that year and region aren't loaded or the size is under 1. */
    public function guideline(int $year, string $region, int $familySize): ?int
    {
        $row = $this->byKey["$year:$region"] ?? null;
        if ($row === null || $familySize < 1) {
            return null;
        }
        return $row['firstPerson'] + ($familySize - 1) * $row['eachAdditional'];
    }

    /** Annual income as a percentage of the household's guideline. */
    public function percent(float $annualIncome, int $familySize, int $year, string $region): ?float
    {
        $guideline = $this->guideline($year, $region, $familySize);
        if ($guideline === null || $annualIncome < 0) {
            return null;
        }
        return 100 * $annualIncome / $guideline;
    }

    public function source(int $year, string $region): ?string
    {
        return $this->byKey["$year:$region"]['source'] ?? null;
    }

    /** The newest year loaded for a region. */
    public function latestYear(string $region): ?int
    {
        $years = [];
        foreach (array_keys($this->byKey) as $key) {
            [$year, $keyRegion] = explode(':', $key);
            if ($keyRegion === $region) {
                $years[] = (int)$year;
            }
        }
        return $years === [] ? null : max($years);
    }
}
