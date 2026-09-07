# Renderer comparison — ADR-0021 acceptance prerequisite 1

**Disposable.** Throwaway code that exists to answer one question: which headless runtime
renders plan 27's template contract correctly, and at what image closure. Deleted once the
finding is written. It is not the beginning of the renderer.

Candidates render the *same* document (`contract/template.json`) against the *same* profile
(`contract/profile.json`), exercising the five cases prerequisite 1 names.
