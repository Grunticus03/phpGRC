<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mime_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('value', 191);
            $table->enum('match_type', ['exact', 'prefix'])->default('exact');
            $table->string('label', 191);
            $table->timestamps();

            $table->unique(['value', 'match_type'], 'mime_labels_value_match_type_unique');
        });

        $data = [
            ['match_type' => 'exact', 'value' => 'application/pdf', 'label' => 'PDF document'],
            ['match_type' => 'exact', 'value' => 'application/msword', 'label' => 'Microsoft Word document'],
            ['match_type' => 'exact', 'value' => 'application/vnd.ms-word', 'label' => 'Microsoft Word document'],
            ['match_type' => 'exact', 'value' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'label' => 'Microsoft Word document'],
            ['match_type' => 'exact', 'value' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'label' => 'Microsoft Word template'],
            ['match_type' => 'exact', 'value' => 'application/rtf', 'label' => 'Rich Text document'],
            ['match_type' => 'exact', 'value' => 'application/vnd.ms-excel', 'label' => 'Microsoft Excel spreadsheet'],
            ['match_type' => 'exact', 'value' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'label' => 'Microsoft Excel spreadsheet'],
            ['match_type' => 'exact', 'value' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template', 'label' => 'Microsoft Excel template'],
            ['match_type' => 'exact', 'value' => 'application/vnd.ms-powerpoint', 'label' => 'Microsoft PowerPoint presentation'],
            ['match_type' => 'exact', 'value' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'label' => 'Microsoft PowerPoint presentation'],
            ['match_type' => 'exact', 'value' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'label' => 'Microsoft PowerPoint slideshow'],
            ['match_type' => 'exact', 'value' => 'application/vnd.apple.keynote', 'label' => 'Apple Keynote presentation'],
            ['match_type' => 'exact', 'value' => 'application/vnd.apple.numbers', 'label' => 'Apple Numbers spreadsheet'],
            ['match_type' => 'exact', 'value' => 'application/vnd.apple.pages', 'label' => 'Apple Pages document'],
            ['match_type' => 'exact', 'value' => 'application/vnd.android.package-archive', 'label' => 'Android APK package'],
            ['match_type' => 'exact', 'value' => 'application/x-msdownload', 'label' => 'Windows executable'],
            ['match_type' => 'exact', 'value' => 'application/x-ms-installer', 'label' => 'Windows installer package'],
            ['match_type' => 'exact', 'value' => 'application/x-msi', 'label' => 'Windows installer package'],
            ['match_type' => 'exact', 'value' => 'application/x-sh', 'label' => 'Shell script'],
            ['match_type' => 'exact', 'value' => 'application/x-python-code', 'label' => 'Python script'],
            ['match_type' => 'exact', 'value' => 'application/javascript', 'label' => 'JavaScript file'],
            ['match_type' => 'exact', 'value' => 'text/javascript', 'label' => 'JavaScript file'],
            ['match_type' => 'exact', 'value' => 'application/json', 'label' => 'JSON data'],
            ['match_type' => 'exact', 'value' => 'application/xml', 'label' => 'XML document'],
            ['match_type' => 'exact', 'value' => 'text/xml', 'label' => 'XML document'],
            ['match_type' => 'exact', 'value' => 'text/html', 'label' => 'HTML document'],
            ['match_type' => 'exact', 'value' => 'text/markdown', 'label' => 'Markdown document'],
            ['match_type' => 'exact', 'value' => 'text/css', 'label' => 'CSS stylesheet'],
            ['match_type' => 'exact', 'value' => 'text/csv', 'label' => 'CSV file'],
            ['match_type' => 'exact', 'value' => 'text/plain', 'label' => 'Plain text'],
            ['match_type' => 'exact', 'value' => 'application/zip', 'label' => 'ZIP archive'],
            ['match_type' => 'exact', 'value' => 'application/x-zip-compressed', 'label' => 'ZIP archive'],
            ['match_type' => 'exact', 'value' => 'application/x-7z-compressed', 'label' => '7-Zip archive'],
            ['match_type' => 'exact', 'value' => 'application/x-rar-compressed', 'label' => 'RAR archive'],
            ['match_type' => 'exact', 'value' => 'application/vnd.rar', 'label' => 'RAR archive'],
            ['match_type' => 'exact', 'value' => 'application/x-tar', 'label' => 'TAR archive'],
            ['match_type' => 'exact', 'value' => 'application/gzip', 'label' => 'GZIP archive'],
            ['match_type' => 'exact', 'value' => 'application/x-bzip', 'label' => 'BZIP archive'],
            ['match_type' => 'exact', 'value' => 'application/x-bzip2', 'label' => 'BZIP2 archive'],
            ['match_type' => 'exact', 'value' => 'application/x-iso9660-image', 'label' => 'ISO disk image'],
            ['match_type' => 'exact', 'value' => 'application/octet-stream', 'label' => 'Binary file'],
            ['match_type' => 'exact', 'value' => 'application/sql', 'label' => 'SQL script'],
            ['match_type' => 'exact', 'value' => 'image/jpeg', 'label' => 'JPEG image'],
            ['match_type' => 'exact', 'value' => 'image/jpg', 'label' => 'JPEG image'],
            ['match_type' => 'exact', 'value' => 'image/png', 'label' => 'PNG image'],
            ['match_type' => 'exact', 'value' => 'image/gif', 'label' => 'GIF image'],
            ['match_type' => 'exact', 'value' => 'image/webp', 'label' => 'WEBP image'],
            ['match_type' => 'exact', 'value' => 'image/bmp', 'label' => 'Bitmap image'],
            ['match_type' => 'exact', 'value' => 'image/tiff', 'label' => 'TIFF image'],
            ['match_type' => 'exact', 'value' => 'image/svg+xml', 'label' => 'SVG image'],
            ['match_type' => 'exact', 'value' => 'image/heic', 'label' => 'HEIC image'],
            ['match_type' => 'exact', 'value' => 'audio/mpeg', 'label' => 'MP3 audio'],
            ['match_type' => 'exact', 'value' => 'audio/mp3', 'label' => 'MP3 audio'],
            ['match_type' => 'exact', 'value' => 'audio/wav', 'label' => 'WAV audio'],
            ['match_type' => 'exact', 'value' => 'audio/x-wav', 'label' => 'WAV audio'],
            ['match_type' => 'exact', 'value' => 'audio/ogg', 'label' => 'Ogg audio'],
            ['match_type' => 'exact', 'value' => 'audio/flac', 'label' => 'FLAC audio'],
            ['match_type' => 'exact', 'value' => 'audio/aac', 'label' => 'AAC audio'],
            ['match_type' => 'exact', 'value' => 'audio/webm', 'label' => 'WebM audio'],
            ['match_type' => 'exact', 'value' => 'video/mp4', 'label' => 'MP4 video'],
            ['match_type' => 'exact', 'value' => 'video/mpeg', 'label' => 'MPEG video'],
            ['match_type' => 'exact', 'value' => 'video/webm', 'label' => 'WebM video'],
            ['match_type' => 'exact', 'value' => 'video/quicktime', 'label' => 'QuickTime video'],
            ['match_type' => 'exact', 'value' => 'video/x-msvideo', 'label' => 'AVI video'],
            ['match_type' => 'exact', 'value' => 'video/x-matroska', 'label' => 'Matroska video'],
            ['match_type' => 'exact', 'value' => 'video/x-ms-wmv', 'label' => 'WMV video'],
            ['match_type' => 'exact', 'value' => 'application/vnd.google-apps.document', 'label' => 'Google Docs document'],
            ['match_type' => 'exact', 'value' => 'application/vnd.google-apps.presentation', 'label' => 'Google Slides presentation'],
            ['match_type' => 'exact', 'value' => 'application/vnd.google-apps.spreadsheet', 'label' => 'Google Sheets spreadsheet'],
            ['match_type' => 'prefix', 'value' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.', 'label' => 'Microsoft Word document'],
            ['match_type' => 'prefix', 'value' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.', 'label' => 'Microsoft Excel spreadsheet'],
            ['match_type' => 'prefix', 'value' => 'application/vnd.openxmlformats-officedocument.presentationml.', 'label' => 'Microsoft PowerPoint presentation'],
            ['match_type' => 'prefix', 'value' => 'application/vnd.ms-powerpoint.', 'label' => 'Microsoft PowerPoint presentation'],
            ['match_type' => 'prefix', 'value' => 'application/vnd.ms-excel.', 'label' => 'Microsoft Excel spreadsheet'],
        ];

        $now = now();

        $records = array_map(static function (array $entry) use ($now): array {
            return [
                'match_type' => $entry['match_type'],
                'value' => strtolower($entry['value']),
                'label' => $entry['label'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $data);

        DB::table('mime_labels')->insert($records);
    }

    public function down(): void
    {
        Schema::dropIfExists('mime_labels');
    }
};
