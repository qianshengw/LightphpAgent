#!/usr/bin/env python3
"""Python side of the differential harness.

The Python original is treated as a READ-ONLY oracle: this script never
imports it from its original location. It copies the package to a temporary
directory, neutralises the package __init__ (it pulls in the optional `mcp`
dependency), imports from the copy, and prints the observations as JSON:

    { "<id>": <observed>, ... }

The observed values carry the SAME meaning as the PHP side:
    kind=bool   -> True  = the write / call was ALLOWED
                   False = it was BLOCKED
    kind=scalar -> an int / string describing the outcome

Usage: python python_runner.py <scenarios.json> <path-to-python-original>
"""

from __future__ import annotations

import importlib
import json
import os
import shutil
import sys
import tempfile


def load_oracle(original_root: str):
    """Copy the original package to a temp dir and import it from there."""
    work = tempfile.mkdtemp(prefix="la_diff_oracle_")
    pkg = os.path.join(work, "laoracle")
    shutil.copytree(original_root, pkg)
    # The package __init__ imports core.py, which needs the optional `mcp`
    # dependency. The scenarios only need leaf modules, so neutralise it.
    with open(os.path.join(pkg, "__init__.py"), "w", encoding="utf-8") as handle:
        handle.write("")
    sys.path.insert(0, work)
    return work, pkg


def observe_write_patterns(mod, spec):
    policy = mod.MemoryPolicy(reject_write_patterns=list(spec.get("patterns") or []))
    candidate = str(spec.get("candidate", ""))
    try:
        decision = policy.allows_write(candidate)
    except Exception:
        # Python raises re.error on a genuinely broken pattern; the write is
        # interrupted, i.e. blocked.
        return False
    return bool(decision.allowed)


def observe_tokenize(mod, spec):
    pool = mod.SharedMemoryPool()
    pool.store(str(spec.get("stored", "")), "alice", metadata={"source": "user"})
    result = pool.retrieve(str(spec.get("query", "")), "alice")
    return len(result.get("results") or [])


def observe_recall_partition(mod, spec):
    pool = mod.SharedMemoryPool()
    for record in spec.get("records") or []:
        pool.store(
            str(record.get("memory", "")),
            "alice",
            metadata={"source": "user", "scope": str(record.get("scope", "user"))},
        )
    scope = spec.get("scope")
    result = pool.retrieve(
        str(spec.get("query", "")),
        "alice",
        scope=scope,
    )
    return len(result.get("results") or [])


def observe_hook_failure_mode(mod, spec):
    def boom(_ctx):
        raise RuntimeError("boom")

    if spec.get("wrapped_in_policy_hook"):
        hooks = [mod.PolicyHook(boom, failure_mode="block")]
    else:
        hooks = [boom]
    manager = mod.HookManager(hooks)
    ctx = mod.HookContext(phase="before_tool_call", payload={"tool_name": "demo"})
    decision = manager.run(ctx)
    action = getattr(decision, "action", None)
    return str(getattr(action, "value", action))


def observe_capability_scope(mod, spec):
    class _Provider(mod.BaseCapabilityProvider):
        def __init__(self, capability_spec, name):
            super().__init__([capability_spec])
            self.name = name

        def invoke(self, capability, *args, **kwargs):
            return "invoked"

    strict = _Provider(
        mod.CapabilitySpec(
            name="file.write",
            risk=mod.CapabilityRisk.DESTRUCTIVE,
            write=True,
            requires_approval=True,
        ),
        "files",
    )
    loose = _Provider(
        mod.CapabilitySpec(
            name="file.write",
            risk=mod.CapabilityRisk.READ_ONLY,
            read=True,
        ),
        "files",
    )
    registry = mod.CapabilityRegistry()
    registry.register(strict, scope=mod.CapabilityScope.RUNTIME)
    registry.register(loose, scope=mod.CapabilityScope.AGENT, owner_id="a1")

    resolved = registry.resolve("file.write", mod.RuntimeContext(agent_id="a1"))
    capability = resolved.capabilities["file.write"]
    risk = getattr(capability.risk, "value", capability.risk)
    return f"{risk}|{'approval' if capability.requires_approval else 'no-approval'}"


def observe_sandbox_checker(mod, spec):
    # The Python original exposes the checker as a module-level function
    # (`_safe_import_check`), the PHP port wraps it in a class.
    allowed, _reason = mod._safe_import_check(str(spec.get("code", "")))
    return bool(allowed)


def observe_permission_narrow(mod, spec):
    parent = mod.SecurityContext(
        tenant_id="t",
        project_id="p",
        policy_version=str(spec.get("parent_policy_version", "1")),
        approval_token_ids=list(spec.get("parent_token_ids") or []),
    )
    child = parent.narrow(task_id="child")
    return f"{child.policy_version}|{len(child.approval_token_ids)}"


def observe_session_event(mod, spec):
    event = mod.SessionEvent(
        type="message",
        session_id="s1",
        data={"content": str(spec.get("payload", ""))},
    )
    payload = event.to_dict()
    return "ok" if payload.get("data", {}).get("content") is not None else "empty"


def observe_context_budget(mod, spec):
    chars = int(spec.get("chars", 0))
    text = ("中" if spec.get("cjk") else "a") * chars
    budget = mod.ContextBudget(max_tokens=100000, reserved_output_tokens=0)
    return budget.estimate([{"role": "user", "content": text}])


def observe_subagent_permissions(mod, spec):
    # CapabilityRisk lives in the capabilities module; runtime only re-exports
    # PermissionSet.
    caps = importlib.import_module("laoracle.capabilities")
    manager = mod.SubagentManager()
    # A NARROW parent ...
    narrow = caps.PermissionSet(
        allowed=frozenset({"fs.read"}),
        max_risk=caps.CapabilityRisk.READ_ONLY,
    )
    # ... and a caller-supplied set for the child that is WIDER than the
    # parent actually holds.
    wider = caps.PermissionSet(
        allowed=frozenset({"fs.read", "fs.write", "net.fetch"}),
        max_risk=caps.CapabilityRisk.DESTRUCTIVE,
    )
    try:
        parent = manager.register(object(), parent_permissions=narrow)
        child = manager.register(
            object(), parent_agent_id=parent.agent_id, parent_permissions=wider
        )
    except Exception:
        # The registration was REFUSED: the child never got the wider set.
        return False
    return bool(child.permissions.allows("fs.write", caps.CapabilityRisk.DESTRUCTIVE))


def observe_fts_owner_filter(mod, spec):
    path = os.path.join(tempfile.gettempdir(), f"la_diff_fts_{os.getpid()}.sqlite3")
    try:
        os.unlink(path)
    except OSError:
        pass
    rag = mod.SqliteFTSRetrievalProvider(path, chunk_size=400, chunk_overlap=40)
    foreign = int(spec.get("foreign_documents", 60))
    for i in range(foreign):
        rag.ingest(
            mod.RetrievalDocument(
                content=f"machine learning document {i}",
                title=f"doc{i}",
                source="docs",
                scope="workspace",
                owner_id="bob",
            )
        )
    rag.ingest(
        mod.RetrievalDocument(
            content="machine learning alice unique document",
            title="alice",
            source="docs",
            scope="workspace",
            owner_id="alice",
        )
    )
    hits = rag.search("machine learning", limit=5, scope="workspace", owner_id="alice")
    try:
        os.unlink(path)
    except OSError:
        pass
    return len(hits)


GROUPS = {
    "memory-write-patterns": ("protocol", observe_write_patterns),
    "memory-tokenize": ("shared_memory", observe_tokenize),
    "memory-recall-partition": ("shared_memory", observe_recall_partition),
    "hook-failure-mode": ("hooks", observe_hook_failure_mode),
    "capability-scope": ("capabilities", observe_capability_scope),
    "sandbox-checker": ("builtin_tools.python_executor", observe_sandbox_checker),
    "permission-narrow": ("security", observe_permission_narrow),
    "session-event": ("session", observe_session_event),
    "context-budget": ("session", observe_context_budget),
    "subagent-permissions": ("runtime", observe_subagent_permissions),
    "fts-owner-filter": ("knowledge", observe_fts_owner_filter),
}


def main() -> int:
    if len(sys.argv) < 3:
        sys.stderr.write("usage: python_runner.py <scenarios.json> <python-original>\n")
        return 2
    scenario_file, original_root = sys.argv[1], sys.argv[2]
    with open(scenario_file, encoding="utf-8") as handle:
        definition = json.load(handle)

    work, pkg = load_oracle(original_root)
    observations = {}
    modules = {}
    try:
        for scenario in definition.get("scenarios", []):
            sid = str(scenario.get("id"))
            group = str(scenario.get("group"))
            spec = scenario.get("input") or {}
            module_name, observer = GROUPS.get(group, (None, None))
            if observer is None:
                observations[sid] = "unsupported-group"
                continue
            if module_name not in modules:
                try:
                    modules[module_name] = importlib.import_module("laoracle." + module_name)
                except Exception as exc:
                    observations[sid] = f"python-import-error:{type(exc).__name__}"
                    continue
            try:
                observations[sid] = observer(modules[module_name], spec)
            except Exception as exc:
                observations[sid] = f"python-error:{type(exc).__name__}"
    finally:
        shutil.rmtree(work, ignore_errors=True)

    print(json.dumps(observations, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
