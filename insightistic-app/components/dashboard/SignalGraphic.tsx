export function SignalGraphic({ compact = false }: { compact?: boolean }) {
  return (
    <div className={`ins-signal-art ${compact ? "min-h-[8rem]" : ""}`} aria-hidden="true">
      <span className="ins-signal-art__label">LIVE SIGNALS</span>
      <svg className="ins-signal-art__svg" viewBox="0 0 520 170" preserveAspectRatio="none">
        <path className="ins-signal-art__path" d="M-10 128 C 40 124, 60 72, 104 90 S 160 150, 202 108 S 260 54, 302 88 S 362 134, 397 80 S 460 28, 530 46" />
        <path className="ins-signal-art__path" d="M-10 146 C 56 152, 74 110, 122 118 S 184 142, 230 122 S 304 91, 346 111 S 420 144, 530 108" opacity=".35" />
        <circle className="ins-signal-art__dot" cx="302" cy="88" r="5" />
        <circle className="ins-signal-art__dot" cx="397" cy="80" r="4" style={{ animationDelay: "-0.8s" }} />
      </svg>
      <span className="ins-signal-art__chip ins-signal-art__chip--one">Search</span>
      <span className="ins-signal-art__chip ins-signal-art__chip--two">AI visibility</span>
      <span className="ins-signal-art__chip ins-signal-art__chip--three">Revenue</span>
    </div>
  );
}
