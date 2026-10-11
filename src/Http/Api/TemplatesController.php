<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\Template;

class TemplatesController
{
    public function index()
    {
        return Template::orderBy('name')->get(['id', 'name', 'updated_at']);
    }

    public function store(Request $r)
    {
        return response()->json(Template::create($this->validated($r)), 201);
    }

    public function show(int $id)
    {
        return Template::findOrFail($id);
    }

    public function update(Request $r, int $id)
    {
        $t = Template::findOrFail($id);
        $t->update($this->validated($r));

        return $t;
    }

    public function destroy(int $id)
    {
        Template::findOrFail($id)->delete();

        return response()->noContent();
    }

    private function validated(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:255', 'content' => 'required|string|max:1000000']);
    }
}
