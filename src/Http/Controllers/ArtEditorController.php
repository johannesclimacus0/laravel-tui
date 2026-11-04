<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LaravelTui\LaravelTui\Art\ArtRepository;
use Symfony\Component\HttpFoundation\Response;

class ArtEditorController
{
    public function index(): View
    {
        return view('laravel-tui::art-editor');
    }

    public function asset(string $file): Response
    {
        abort_unless(in_array($file, ['editor.js', 'editor.css'], true), 404);

        return response()->file(dirname(__DIR__, 3).'/resources/art-editor/'.$file, [
            'Content-Type' => $file === 'editor.js' ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function data(ArtRepository $arts): JsonResponse
    {
        return response()->json($arts->editorData())->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, ArtRepository $arts): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 2097152, 413);
        $document = $request->validate([
            'version' => ['required', 'integer', 'in:1'],
            'palette' => ['required', 'array', 'min:1', 'max:32'],
            'grids' => ['present', 'array'],
            'sources' => ['present', 'array'],
        ]);

        try {
            $arts->save($document);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['colors' => $error->getMessage()]);
        }

        return response()->json(['message' => 'Colors saved. Restart the UI to load them.']);
    }
}
