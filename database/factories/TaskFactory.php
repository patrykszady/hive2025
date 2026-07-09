<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        // belongs_to_vendor_id / created_by_user_id are stamped by the
        // TaskObserver from the authenticated user + the task's project.
        return [
            'title' => $this->faker->sentence(3),
            'order' => 0,
            'type' => 'Task',
        ];
    }
}
