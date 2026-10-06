/**
 * Stand-in body for a route whose real screen hasn't been built yet. Each
 * placeholder page keeps its own component/route so the nav resolves and the
 * shell can be exercised end to end; the real implementations replace the
 * bodies without touching the route table.
 *
 * The `<main>` landmark lives in `AppLayout`, so this renders a heading and
 * copy only.
 */
export function PagePlaceholder({ title, children }: { title: string; children: string }) {
  return (
    <>
      <h1 className="page__title">{title}</h1>
      <p className="page__intro">{children}</p>
    </>
  )
}
