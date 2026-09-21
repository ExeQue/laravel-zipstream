<!-- For people working on the package itself - see ../README.md -->

# Testing against S3 with MinIO

`tests/Feature/SaveToS3Test.php` runs a real multipart upload through `saveToDisk()` against an S3-compatible
server. It catches problems a fake disk can't, such as the AWS SDK rewinding the body after probing its size.

The test is **skipped unless `ZIPSTREAM_S3_ENDPOINT` is set**, so it never runs on CI. Run it locally before
changing anything on the `saveToDisk()` path.

## 1. Start MinIO

`docker-compose.yml` has a `minio` service:

```bash
docker compose up -d minio
```

| What        | Value                   |
|-------------|-------------------------|
| S3 API      | `http://localhost:9100` |
| Web console | `http://localhost:9101` |
| Access key  | `minioadmin`            |
| Secret key  | `minioadmin`            |

Wait until it is ready:

```bash
curl -f http://localhost:9100/minio/health/live
```

> **Image not found?** `minio/minio` can no longer be pulled from Docker Hub without logging in
> (`pull access denied for minio/minio`). If `docker compose up` fails with that error, change `image:` on the `minio`
> service to a MinIO image you can access. The test only needs an S3-compatible API on port 9000 inside the container with the
> credentials above.

## 2. Run the tests

From the host:

```bash
ZIPSTREAM_S3_ENDPOINT=http://localhost:9100 vendor/bin/pest
```

Inside the `php` container, `ZIPSTREAM_S3_ENDPOINT` is already set to `http://minio:9000`. Keep MinIO running
alongside it:

```bash
docker compose run --rm php vendor/bin/pest
```

You don't need to create anything first: the test creates the `zipstream` bucket if it's missing. It removes the
archive it uploads, whether the test passes or fails.

To run only the S3 test:

```bash
ZIPSTREAM_S3_ENDPOINT=http://localhost:9100 vendor/bin/pest tests/Feature/SaveToS3Test.php
```

## 3. Stop MinIO

```bash
docker compose down
```

The data lives inside the container, so nothing is kept between runs.

## Troubleshooting

- **Test is skipped:** `ZIPSTREAM_S3_ENDPOINT` isn't set in the shell that runs Pest.
- **Connection refused:** MinIO isn't running, or isn't ready yet. Check the health endpoint above.
- **`Cannot seek a PumpStream`:** the rewindable head in `Builder::rewindableHead()` is too small for how much
  the AWS SDK read before rewinding. That's a real bug, not a setup problem.
