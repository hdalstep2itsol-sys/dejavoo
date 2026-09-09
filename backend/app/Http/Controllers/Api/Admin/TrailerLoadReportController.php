<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TrailerLoadReportRequest;
use App\Http\Resources\TrailerLoadReportResource;
use App\Models\Location;
use App\Models\User;
use App\Services\SimpleXlsxWriter;
use App\Services\TrailerLoadReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrailerLoadReportController extends Controller
{
    public function index(
        TrailerLoadReportRequest $request,
        TrailerLoadReportService $reports,
    ): AnonymousResourceCollection {
        $perPage = $request->integer('per_page', 25);
        $loads = $reports
            ->query($this->filters($request))
            ->paginate($perPage)
            ->withQueryString();

        return TrailerLoadReportResource::collection($loads)->additional([
            'filter_options' => [
                'locations' => Location::query()
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'drivers' => User::query()
                    ->where('role', UserRole::Driver->value)
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_active']),
            ],
        ]);
    }

    public function csv(
        TrailerLoadReportRequest $request,
        TrailerLoadReportService $reports,
    ): StreamedResponse {
        $loads = $reports->query($this->filters($request))->get();

        return response()->streamDownload(function () use ($loads, $reports) {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $reports->headers());
            foreach ($loads as $load) {
                fputcsv($output, $reports->csvRow($load));
            }
            fclose($output);
        }, $this->filename('csv'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function xlsx(
        TrailerLoadReportRequest $request,
        TrailerLoadReportService $reports,
        SimpleXlsxWriter $writer,
    ): BinaryFileResponse {
        $loads = $reports->query($this->filters($request))->get();
        $rows = $loads->map(fn ($load) => $reports->exportRow($load))->all();
        $path = $writer->write($reports->headers(), $rows);

        return response()->download($path, $this->filename('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(TrailerLoadReportRequest $request): array
    {
        return collect($request->validated())
            ->except(['page', 'per_page'])
            ->all();
    }

    private function filename(string $extension): string
    {
        return 'trailer-load-report-'.now()->format('Y-m-d-His').'.'.$extension;
    }
}
