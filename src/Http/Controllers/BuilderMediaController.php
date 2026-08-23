<?php

declare(strict_types=1);

namespace Magna\Pages\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Magna\Media\Exceptions\MediaIngestException;
use Magna\Media\Exceptions\MimeTypeNotAllowedException;
use Magna\Media\Media;
use Magna\Media\MediaIngestor;

/**
 * Pictures, for the builder.
 *
 * A `media` field rendered as a plain text box before this existed, which
 * asked an editor to type a media id from memory — so the logo block had
 * no way to choose an image and the image block had none either. This is
 * the two things a picker needs: what is already here, and a way to add
 * something that is not.
 *
 * Its own endpoint rather than the management API because the builder is a
 * SESSION, not a token client: everything else it calls lives under
 * /pages-builder for exactly that reason. The permissions are the media
 * ones, unchanged — being in the builder is not a way around them.
 */
final class BuilderMediaController
{
    /** A picker is a shortcut to recent work, not the media library. */
    private const LIMIT = 60;

    public function __construct(private readonly MediaIngestor $ingestor) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('media.view');

        $search = trim((string) $request->string('q')->value());

        $query = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->latest('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('original_filename', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%')
                    ->orWhere('alt', 'like', '%'.$search.'%');
            });
        }

        return response()->json([
            'media' => $query->limit(self::LIMIT)->get()->map(fn (Media $media): array => [
                'id' => $media->id,
                'name' => $media->title ?? $media->original_filename,
                'alt' => $media->alt,
                'url' => Storage::disk($media->disk)->url($media->path),
                'mime' => $media->mime_type,
            ])->all(),
        ]);
    }

    /**
     * Add a picture: an uploaded file, or SVG markup pasted in.
     *
     * Pasted markup is written to a temporary file and ingested through the
     * SAME pipeline an upload uses — which means it passes the same SVG
     * sanitiser. A second path that accepted markup directly would be a
     * second thing to keep safe, and the one that skipped the sanitiser
     * would be the one somebody pasted a script into.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('media.upload');

        $file = $request->file('file');
        $markup = trim((string) $request->string('svg')->value());

        if (! $file instanceof UploadedFile && $markup === '') {
            return response()->json(['message' => 'Choose a file, or paste some SVG.'], 422);
        }

        $temporary = null;

        if ($file instanceof UploadedFile) {
            $path = $file->getRealPath();
            if ($path === false) {
                return response()->json(['message' => 'Could not read that file.'], 422);
            }
            $name = $file->getClientOriginalName();
        } else {
            // Cheap shape check before writing anything: a paste that is not
            // SVG at all should be refused where the editor can see it,
            // rather than deep inside the ingest pipeline.
            if (stripos($markup, '<svg') === false) {
                return response()->json(['message' => 'That does not look like SVG markup.'], 422);
            }

            $temporary = tempnam(sys_get_temp_dir(), 'magna-svg');
            if ($temporary === false) {
                return response()->json(['message' => 'Could not stage that SVG.'], 422);
            }

            file_put_contents($temporary, $markup);
            $path = $temporary;
            $name = trim((string) $request->string('name')->value());
            $name = $name === '' ? 'pasted.svg' : $name;
            $name = str_ends_with(strtolower($name), '.svg') ? $name : $name.'.svg';
        }

        try {
            $media = $this->ingestor->ingest(
                $path,
                $name,
                alt: ($alt = trim((string) $request->string('alt')->value())) !== '' ? $alt : null,
            );
        } catch (MimeTypeNotAllowedException|MediaIngestException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return response()->json([
            'id' => $media->id,
            'name' => $media->title ?? $media->original_filename,
            'alt' => $media->alt,
            'url' => Storage::disk($media->disk)->url($media->path),
            'mime' => $media->mime_type,
        ], 201);
    }
}
