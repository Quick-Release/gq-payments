# Domain Docs

This repo uses a single-context layout: `GLOSSARY.md` at the root and architectural decision records in `docs/adr/`.

## Before exploring

Read `GLOSSARY.md` and any ADRs in `docs/adr/` relevant to the area you are about to work in.

If these files are absent, proceed silently. The `domain-modeling` skill creates them lazily when terms or decisions are resolved.

## Use the glossary's vocabulary

When naming domain concepts in issues, proposals, hypotheses, or tests, use the terms defined in `GLOSSARY.md`. If a needed concept is missing, reconsider whether it belongs to the domain; record real gaps for `domain-modeling`.

## Flag ADR conflicts

If a proposal contradicts an existing ADR, identify the ADR and explain why the decision should be reopened.
