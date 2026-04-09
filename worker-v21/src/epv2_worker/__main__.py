"""Entry point for python -m epv2_worker"""
import uvicorn
from .server import app

if __name__ == "__main__":
    uvicorn.run(
        "epv2_worker.server:app",
        host="127.0.0.1",
        port=8765,
        log_level="info",
        access_log=True,
    )
