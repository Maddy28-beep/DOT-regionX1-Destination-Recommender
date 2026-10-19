<?php

namespace Tests\Unit;

use App\Support\SearchTerm;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

class SearchTermTest extends TestCase
{
    private function names(string $term): array
    {
        $db = new Capsule;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->getConnection()->statement('create table t (name text)');

        foreach (['Eden Nature Park', 'Mount Apo', '100% Davao', 'Sun_Beach'] as $n) {
            $db->getConnection()->table('t')->insert(['name' => $n]);
        }

        return SearchTerm::contains($db->getConnection()->table('t'), 'name', $term)->orderBy('name')->pluck('name')->all();
    }

    public function test_it_ignores_capitals_and_matches_anywhere_in_the_name(): void
    {
        $this->assertSame(['Eden Nature Park'], $this->names('eden'));
        $this->assertSame(['Eden Nature Park'], $this->names('EDEN'));
        $this->assertSame(['Eden Nature Park'], $this->names('nature'));
    }

    public function test_percent_and_underscore_are_ordinary_characters(): void
    {
        $this->assertSame(['100% Davao'], $this->names('%'));
        $this->assertSame(['Sun_Beach'], $this->names('_'));
        $this->assertSame([], $this->names('zzz'));
    }
}
