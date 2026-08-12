import asyncio
import logging
import os


LISTEN_HOST = os.getenv("LISTEN_HOST", "172.19.0.1")
LISTEN_PORT = int(os.getenv("LISTEN_PORT", "17895"))
UPSTREAM_HOST = os.getenv("UPSTREAM_HOST", "127.0.0.1")
UPSTREAM_PORT = int(os.getenv("UPSTREAM_PORT", "7890"))

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("telegram-proxy-bridge")


async def copy_stream(reader, writer):
    try:
        while True:
            data = await reader.read(65536)
            if not data:
                break
            writer.write(data)
            await writer.drain()
    except (asyncio.CancelledError, ConnectionError):
        pass


async def handle_client(client_reader, client_writer):
    upstream_writer = None
    try:
        upstream_reader, upstream_writer = await asyncio.open_connection(
            UPSTREAM_HOST,
            UPSTREAM_PORT,
        )
        await asyncio.gather(
            copy_stream(client_reader, upstream_writer),
            copy_stream(upstream_reader, client_writer),
        )
    except Exception as exception:
        log.warning("bridge connection failed: %s", type(exception).__name__)
    finally:
        for writer in (upstream_writer, client_writer):
            if writer is not None:
                writer.close()
        for writer in (upstream_writer, client_writer):
            if writer is not None:
                try:
                    await writer.wait_closed()
                except Exception:
                    pass


async def main():
    server = await asyncio.start_server(
        handle_client,
        LISTEN_HOST,
        LISTEN_PORT,
        reuse_address=True,
    )
    log.info(
        "listening=%s:%d upstream=%s:%d",
        LISTEN_HOST,
        LISTEN_PORT,
        UPSTREAM_HOST,
        UPSTREAM_PORT,
    )
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
