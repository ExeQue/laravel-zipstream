---
name: laravel-zipstream
description: Generate and stream ZIP archives in Laravel using a fluent, memory-efficient API.
---

# Laravel ZipStream

## When to use this skill
Use this skill when a Laravel application needs to create ZIP files from various sources (disks, local paths, or raw content) and either stream them to the browser, save them to a disk, or obtain them as a string/stream. It wraps the `maennchen/zipstream-php` library to provide a Laravel-friendly interface.

## Benefits
- **Memory Efficiency**: Streams content directly to the output without loading the entire ZIP into memory.
- **Fluent API**: Clean, chainable methods for adding files and configuring options.
- **Integration**: Works seamlessly with Laravel's filesystem disks and responses.

## Definitions
- **Fluent API**: A method of designing object-oriented APIs that relies on method chaining to provide more readable code.
- **Streamed Response**: A response that sends content to the client in chunks, reducing server memory usage for large files.
- **Zip Facade**: The primary entry point (`ExeQue\ZipStream\Facades\Zip`) for interacting with the library.
- **Deflate**: The standard compression method used in ZIP files.
- **Store**: A ZIP method that adds files without any compression.
- **Zero Header**: A ZIP feature that allows streaming by providing file information at the end of the file instead of the beginning.

## Principles
1. **Prefer Streaming**: Always use `toResponse()` or `saveToDisk()` to handle large archives without exhausting memory.
2. **Explicit Destinations**: Clearly define the path within the ZIP to maintain a clean archive structure.
3. **Smart Compression**: Use `store()` for already compressed formats (images, videos) and `deflate()` for text-based content to optimize performance.
4. **Fluent Chaining**: Build the archive by chaining methods starting from the `Zip` facade.

## Basic Archive Creation
To create a simple ZIP and stream it to the browser:

```php
use ExeQue\ZipStream\Facades\Zip;

return Zip::as('archive_name.zip')
    ->fromDisk('public', 'path/to/file.jpg')
    ->toResponse();
```

## Adding Various Content Sources
The library supports multiple source types:

```php
// From a Laravel Disk
Zip::fromDisk('s3', 'source/path.pdf', 'internal/name.pdf');

// From a Local File Path
Zip::fromLocal('/absolute/path/to/file.log', 'logs/app.log');

// From Raw String/Stream Content
Zip::fromRaw('notes.txt', 'This is the content of the file.');

// Create an Empty Directory
Zip::emptyDirectory('empty_folder');
```

## Configuring File-Specific Options
Use a callback to customize individual files:

```php
use ExeQue\ZipStream\Content\LocalFile;

Zip::fromLocal('file.txt', 'file.txt', function (LocalFile $file) {
    $file->comment('Important file')
         ->deflate()
         ->deflateLevel(9);
});
```

## Global Archive Options
Set options that apply to the entire ZIP:

```php
Zip::as('optimized.zip')
    ->store() // Use 'STORE' method for all files by default
    ->withZeroHeader()
    ->fromDisk('public', 'video.mp4')
    ->toResponse();
```

## Handling the Output
Choose the appropriate output method:

```php
// 1. Stream as Laravel Response
return Zip::toResponse();

// 2. Save to Local Path
Zip::saveToLocal('/path/to/archive.zip');

// 3. Save to Laravel Disk
Zip::saveToDisk('s3', 'backups/archive.zip');

// 4. Get as String
$content = Zip::output();

// 5. Get as PSR-7 Stream
$stream = Zip::output(true);
```
