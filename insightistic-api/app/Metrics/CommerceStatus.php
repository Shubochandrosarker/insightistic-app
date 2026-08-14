<?php

namespace App\Metrics;

/**
 * Canonical WooCommerce order-status classification. This is the ONE place
 * every metric/intelligence service reads its "which statuses count"
 * lists from, instead of each hand-copying its own literal array.
 *
 * Before this class existed, the same classification concept was
 * hand-typed into five places (MetricsAssembler, MetricSnapshotService,
 * CustomerIntelligence, ProductIntelligence, AcquisitionIntelligence) —
 * a real drift risk even where the lists happened to agree, since nothing
 * enforced they'd stay that way.
 *
 * Auditing those five copies surfaced that there are actually TWO
 * intentionally different definitions in production today, not one that
 * had merely drifted:
 *
 * - REVENUE_PAID (includes 'on-hold') is used by MetricsAssembler and
 *   MetricSnapshotService for headline revenue/order KPIs — on-hold orders
 *   (commonly awaiting bank-transfer/COD confirmation) are counted as
 *   attributable revenue.
 * - CONFIRMED_PAID (excludes 'on-hold') is used by CustomerIntelligence,
 *   ProductIntelligence and AcquisitionIntelligence, whose own docblocks
 *   explain the deliberate choice: "money actually taken" — counting
 *   payment-pending orders would inflate RFM/LTV/cohort scores before the
 *   payment is confirmed.
 *
 * P0 preserves BOTH behaviors exactly as they already ran (zero metric
 * value changes) and only removes the hand-copying — it does not decide
 * which definition is "right". See docs/SEMANTIC-METRICS.md and
 * docs/CONVERSATIONAL-ANALYTICS-AUDIT.md for this flagged as an open
 * product decision: whether revenue KPIs and customer/product
 * intelligence should agree on one paid-status definition going forward.
 */
final class CommerceStatus
{
    /** "Attributable revenue" statuses — includes payment-pending on-hold orders. Used by MetricsAssembler / MetricSnapshotService for headline revenue + order KPIs. */
    public const REVENUE_PAID = ['completed', 'processing', 'on-hold'];

    /** "Payment captured" statuses — excludes on-hold. Used by CustomerIntelligence / ProductIntelligence / AcquisitionIntelligence for RFM, cohorts, LTV, affinity, declining-products and acquisition analytics. */
    public const CONFIRMED_PAID = ['completed', 'processing'];

    /** Statuses that count as failed/lost (never became revenue under either definition above). */
    public const FAILED = ['failed', 'cancelled'];
}
