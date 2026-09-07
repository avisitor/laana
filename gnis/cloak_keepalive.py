import asyncio
import cloakbrowser

CDP_PORT = 9242


async def main():
    await cloakbrowser.launch_async(
        headless=True, stealth_args=True,
        args=[f'--remote-debugging-port={CDP_PORT}'])
    print('LAUNCHED', flush=True)
    while True:
        await asyncio.sleep(3600)


asyncio.run(main())
