<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardClass;
use App\Services\Enrichment\ComboCandidateRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComboCandidateRetrieverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['enrichment.embedding.dimensions' => 3]);
    }

    public function test_it_pools_only_cards_sharing_a_class_or_talent(): void
    {
        $class = CardClass::create(['name' => 'Warrior']);
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $card->classes()->attach($class->id);

        $sharer = Card::factory()->create(['functional_text' => 'Draw a card.']);
        $sharer->classes()->attach($class->id);

        $unrelated = Card::factory()->create(['functional_text' => 'Gain 1 life.']);

        $blank = Card::factory()->create(['functional_text' => '']);
        $blank->classes()->attach($class->id);

        Http::fake([
            '*' => Http::response(['data' => [
                ['index' => 0, 'embedding' => [1, 0, 0]],
                ['index' => 1, 'embedding' => [1, 0, 0]],
            ]]),
        ]);

        $results = app(ComboCandidateRetriever::class)->retrieve($card, 8, 50);

        $this->assertSame([$sharer->id], $results->pluck('id')->all());
    }

    public function test_it_ranks_candidates_by_cosine_similarity_and_applies_top_k(): void
    {
        $class = CardClass::create(['name' => 'Warrior']);
        $card = Card::factory()->create(['functional_text' => 'Target text.']);
        $card->classes()->attach($class->id);

        $exactMatch = Card::factory()->create(['functional_text' => 'Exact match text.']);
        $exactMatch->classes()->attach($class->id);

        $orthogonal = Card::factory()->create(['functional_text' => 'Orthogonal text.']);
        $orthogonal->classes()->attach($class->id);

        $opposite = Card::factory()->create(['functional_text' => 'Opposite text.']);
        $opposite->classes()->attach($class->id);

        $vectorsByText = [
            'Target text.' => [1, 0, 0],
            'Exact match text.' => [1, 0, 0],
            'Orthogonal text.' => [0, 1, 0],
            'Opposite text.' => [-1, 0, 0],
        ];

        Http::fake(function ($request) use ($vectorsByText) {
            $input = $request->data()['input'];
            $data = [];

            foreach ($input as $index => $text) {
                $data[] = ['index' => $index, 'embedding' => $vectorsByText[$text]];
            }

            return Http::response(['data' => $data]);
        });

        $results = app(ComboCandidateRetriever::class)->retrieve($card, 2, 50);

        $this->assertCount(2, $results);
        $this->assertSame([$exactMatch->id, $orthogonal->id], $results->pluck('id')->all());
    }

    public function test_it_caps_the_candidate_pool_at_the_configured_limit(): void
    {
        $class = CardClass::create(['name' => 'Warrior']);
        $card = Card::factory()->create(['functional_text' => 'Target text.']);
        $card->classes()->attach($class->id);

        $poolLimit = 3;

        for ($i = 0; $i < $poolLimit + 5; $i++) {
            $candidate = Card::factory()->create(['functional_text' => "Candidate text {$i}."]);
            $candidate->classes()->attach($class->id);
        }

        Http::fake(function ($request) {
            $input = $request->data()['input'];
            $data = [];

            foreach ($input as $index => $text) {
                $data[] = ['index' => $index, 'embedding' => [1, 0, 0]];
            }

            return Http::response(['data' => $data]);
        });

        app(ComboCandidateRetriever::class)->retrieve($card, 8, $poolLimit);

        Http::assertSent(function ($request) use ($poolLimit) {
            return count($request->data()['input']) === $poolLimit + 1;
        });
    }

    public function test_it_makes_no_embedding_call_when_there_are_no_candidates(): void
    {
        Http::preventStrayRequests();

        $card = Card::factory()->create(['functional_text' => 'Target text.']);

        $results = app(ComboCandidateRetriever::class)->retrieve($card, 8, 50);

        $this->assertTrue($results->isEmpty());
        Http::assertNothingSent();
    }
}
