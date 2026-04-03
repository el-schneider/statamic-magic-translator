---
name: dispatch-chain
description: Dispatch worker→reviewer chains for non-trivial tasks. Use when a task involves multiple files, architectural changes, or would benefit from an independent adversarial review.
---

# Dispatch Chain

## When to Use

- Multi-file changes or new features
- Architectural or structural changes
- Tasks from an implementation plan
- Anything where a second pair of eyes catches real bugs

Don't use for trivial fixes, config changes, or single-line edits.

## Single Task

```json
{
  "chain": [
    {
      "agent": "worker",
      "task": "<concrete task description with file paths and plan references>",
      "model": "opencode-go/glm-5",
      "skill": "agent-browser"
    },
    {
      "agent": "reviewer",
      "model": "openai-codex/gpt-5.3-codex",
      "skill": "adversarial-reviewer, agent-browser"
    }
  ],
  "clarify": false
}
```

## Parallel Independent Tasks

Use `worktree: true` to avoid filesystem conflicts.

```json
{
  "chain": [
    {
      "parallel": [
        { "agent": "worker", "task": "<task A>", "model": "anthropic/claude-opus-4-6", "skill": "agent-browser" },
        { "agent": "worker", "task": "<task B>", "model": "anthropic/claude-opus-4-6", "skill": "agent-browser" }
      ],
      "worktree": true
    },
    {
      "agent": "reviewer",
      "model": "openai-codex/gpt-5.3-codex",
      "skill": "adversarial-reviewer, agent-browser"
    }
  ],
  "clarify": false
}
```

## Task Descriptions

The worker starts with a **fresh context**. The task string is all it gets. Be specific:

- Reference file paths and plan sections explicitly
- State the goal and constraints
- Mention test framework, code style expectations

## After the Chain

Read the reviewer's output. If it found real bugs, fix them or dispatch another chain. Don't blindly trust either agent.
