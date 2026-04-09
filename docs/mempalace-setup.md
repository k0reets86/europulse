# MemPalace Setup For Europulse

## Installed Paths

- Repo: `/root/tools/mempalace`
- Virtualenv: `/root/tools/mempalace/.venv`
- Palace: `/root/.mempalace/palace`
- Config: `/root/.mempalace/config.json`
- Identity: `/root/.mempalace/identity.txt`

## Useful Commands

```bash
mempalace status
mempalace search "ready_publish retry_process"
mempalace wake-up
mempalace-reindex-europulse
```

## MCP Command

```bash
mempalace-mcp
```

Equivalent direct command:

```bash
/root/tools/mempalace/.venv/bin/python -m mempalace.mcp_server
```

## Client Examples

### Claude Code

```bash
claude mcp add mempalace -- /root/tools/mempalace/.venv/bin/python -m mempalace.mcp_server
```

### Gemini CLI

```bash
gemini mcp add mempalace /root/tools/mempalace/.venv/bin/python -m mempalace.mcp_server --scope user
```

### Generic MCP Clients

Command:

```bash
/root/tools/mempalace/.venv/bin/python
```

Arguments:

```bash
-m mempalace.mcp_server
```

## Notes

- MemPalace is a separate local memory system. It does not modify WordPress behavior by itself.
- It is useful for indexing project docs, code, runbooks, handoff notes, and operational context.
- The current Europulse project has already been initialized and mined.
