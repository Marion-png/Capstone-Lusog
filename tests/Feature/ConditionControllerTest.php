<?php

namespace Tests\Feature;

use App\Models\Condition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the conditions
        Condition::create(['name' => 'Fever', 'category' => 'General']);
        Condition::create(['name' => 'Cough', 'category' => 'Respiratory']);
        Condition::create(['name' => 'Cold', 'category' => 'Respiratory']);
    }

    /** @test */
    public function index_returns_all_conditions_as_json(): void
    {
        $response = $this->getJson('/api/conditions');

        $response->assertStatus(200)
            ->assertJsonCount(3)
            ->assertJsonStructure(['*' => ['id', 'name', 'category']]);
    }

    /** @test */
    public function index_filters_conditions_by_search_query(): void
    {
        $response = $this->getJson('/api/conditions?search=cough');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Cough']);
    }

    /** @test */
    public function index_search_is_case_insensitive(): void
    {
        $response = $this->getJson('/api/conditions?search=FEVER');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Fever']);
    }

    /** @test */
    public function index_filters_conditions_by_category(): void
    {
        $response = $this->getJson('/api/conditions?category=Respiratory');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['category' => 'Respiratory']);
    }

    /** @test */
    public function index_filters_with_both_search_and_category(): void
    {
        $response = $this->getJson('/api/conditions?search=cold&category=Respiratory');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Cold']);
    }

    /** @test */
    public function store_creates_new_condition_with_school_nurse_role(): void
    {
        $response = $this->withSession(['active_role' => 'school_nurse'])
            ->postJson('/api/conditions', [
                'name' => 'New Condition',
                'category' => 'Custom',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Condition'])
            ->assertJsonStructure(['id', 'name', 'category']);

        $this->assertDatabaseHas('conditions', ['name' => 'New Condition']);
    }

    /** @test */
    public function store_creates_new_condition_with_clinic_staff_role(): void
    {
        $response = $this->withSession(['active_role' => 'clinic_staff'])
            ->postJson('/api/conditions', [
                'name' => 'Another Condition',
                'category' => 'Test',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Another Condition']);

        $this->assertDatabaseHas('conditions', ['name' => 'Another Condition']);
    }

    /** @test */
    public function store_rejects_duplicate_condition_case_insensitive(): void
    {
        $response = $this->withSession(['active_role' => 'school_nurse'])
            ->postJson('/api/conditions', [
                'name' => 'FEVER', // Different case
                'category' => 'General',
            ]);

        $response->assertStatus(409)
            ->assertJsonFragment(['message' => 'A condition with this name already exists.']);
    }

    /** @test */
    public function store_returns_existing_condition_id_on_duplicate(): void
    {
        $fever = Condition::where('name', 'Fever')->first();

        $response = $this->withSession(['active_role' => 'school_nurse'])
            ->postJson('/api/conditions', [
                'name' => 'fever', // Different case
                'category' => 'General',
            ]);

        $response->assertStatus(409)
            ->assertJsonFragment(['id' => $fever->id]);
    }

    /** @test */
    public function store_denies_access_to_class_adviser(): void
    {
        $response = $this->withSession(['active_role' => 'class_adviser'])
            ->postJson('/api/conditions', [
                'name' => 'Unauthorized Condition',
                'category' => 'Test',
            ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Unauthorized. Only clinic staff and school nurses can add conditions.']);

        $this->assertDatabaseMissing('conditions', ['name' => 'Unauthorized Condition']);
    }

    /** @test */
    public function store_requires_condition_name(): void
    {
        $response = $this->withSession(['active_role' => 'school_nurse'])
            ->postJson('/api/conditions', [
                'category' => 'Test',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /** @test */
    public function store_validates_condition_name_max_length(): void
    {
        $response = $this->withSession(['active_role' => 'school_nurse'])
            ->postJson('/api/conditions', [
                'name' => str_repeat('a', 256),
                'category' => 'Test',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /** @test */
    public function store_allows_category_to_be_null(): void
    {
        $response = $this->withSession(['active_role' => 'school_nurse'])
            ->postJson('/api/conditions', [
                'name' => 'No Category Condition',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('conditions', [
            'name' => 'No Category Condition',
            'category' => null,
        ]);
    }

    /** @test */
    public function store_denies_access_without_valid_role(): void
    {
        $response = $this->withSession(['active_role' => 'invalid_role'])
            ->postJson('/api/conditions', [
                'name' => 'Test Condition',
                'category' => 'Test',
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function store_allows_school_head_read_only(): void
    {
        $response = $this->withSession(['active_role' => 'school_head'])
            ->postJson('/api/conditions', [
                'name' => 'School Head Condition',
                'category' => 'Test',
            ]);

        $response->assertStatus(403);
    }
}
