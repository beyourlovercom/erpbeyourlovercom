<?php

namespace App\Modules\Guides\Experience\Controllers;

use App\Modules\Guides\Application\KnowledgeBaseService;
use App\Modules\Guides\Application\MarkdownContentRenderer;
use App\Modules\Guides\Infrastructure\Models\ReleaseNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ReleaseNoteController
{
    public function __construct(private readonly KnowledgeBaseService $service, private readonly MarkdownContentRenderer $renderer) {}

    public function index(Request $request): Response
    {
        $canManage = $request->user()?->can('guides.manage') ?? false;
        $query = ReleaseNote::query();
        if (! $canManage) {
            $query->published();
        }
        $notes = $query->orderByDesc('released_on')->orderByDesc('id')->get()->map(fn (ReleaseNote $note) => $this->payload($note))->all();
        return Inertia::render('Guides/Releases/Index', ['releaseNotes' => $notes, 'canManage' => $canManage]);
    }

    public function show(Request $request, ReleaseNote $releaseNote): Response
    {
        $canManage = $request->user()?->can('guides.manage') ?? false;
        abort_if($releaseNote->status !== 'published' && ! $canManage, 404);
        $rendered = $this->renderer->render($releaseNote->markdown_body);
        return Inertia::render('Guides/Releases/Show', ['releaseNote' => $this->payload($releaseNote), 'html' => $rendered['html'], 'toc' => $rendered['toc'], 'canManage' => $canManage]);
    }

    public function create(): Response
    {
        return Inertia::render('Guides/Releases/Edit', ['releaseNote' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $note = $this->service->createRelease($request->validate($this->rules()), $request->user());
        return redirect()->route('guides.releases.edit', $note)->with('success', '版本记录草稿已创建');
    }

    public function edit(ReleaseNote $releaseNote): Response
    {
        return Inertia::render('Guides/Releases/Edit', ['releaseNote' => $this->payload($releaseNote)]);
    }

    public function update(Request $request, ReleaseNote $releaseNote): RedirectResponse
    {
        $rules = $this->rules($releaseNote->id);
        $this->service->updateRelease($releaseNote, $request->validate($rules), $request->user());
        return back()->with('success', '版本记录已保存');
    }

    public function publish(Request $request, ReleaseNote $releaseNote): RedirectResponse
    {
        $this->service->setReleasePublished($releaseNote, true, $request->user());
        return back()->with('success', '版本记录已发布');
    }

    public function unpublish(Request $request, ReleaseNote $releaseNote): RedirectResponse
    {
        $this->service->setReleasePublished($releaseNote, false, $request->user());
        return back()->with('success', '版本记录已转为草稿');
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'version' => ['required', 'string', 'max:64', Rule::unique('release_notes', 'version')->ignore($ignoreId)],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'markdown_body' => ['required', 'string', 'max:500000'],
            'released_on' => ['required', 'date'],
        ];
    }

    private function payload(ReleaseNote $note): array
    {
        return [
            'id' => $note->id, 'version' => $note->version, 'title' => $note->title, 'summary' => $note->summary,
            'markdown_body' => $note->markdown_body, 'status' => $note->status,
            'released_on' => $note->released_on?->toDateString(), 'published_at' => $note->published_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }
}
