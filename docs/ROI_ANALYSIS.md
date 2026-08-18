# RETURN ON INVESTMENT (ROI) ANALYSIS

## An Economic Evaluation of the BEC Web-Based Defective Equipment Reporting Management System

**Institution:** Batangas Eastern Colleges (BEC), San Juan, Batangas
**Implementing Unit:** Property Management Office (PMO)
**System Under Evaluation:** BEC Web-Based Defective Equipment Reporting Management System
**Evaluation Horizon:** Five (5) years — Year 0 (deployment) through Year 5
**Currency:** Philippine Peso (₱); USD converted at **₱61.02 = US$1.00** (August 2026 average)
**Discount Rate:** 10.0% (NEDA-ICC social discount rate)
**Document Version:** 1.0 — 03 August 2026

---

## ABSTRACT

This paper presents a formal cost–benefit and return-on-investment (ROI) evaluation of the BEC Web-Based Defective Equipment Reporting Management System, an institutional Computerized Maintenance Management System (CMMS) developed as a capstone project for the Property Management Office of Batangas Eastern Colleges. Using a five-year discounted cash-flow framework at the NEDA-prescribed 10% social discount rate, the study quantifies the system's total cost of ownership (TCO) against five monetizable benefit streams: reporter transaction-time savings, PMO administrative processing savings, technician productivity gains, consumable and records-management savings, and reduction in corrective maintenance expenditure.

Under base-case assumptions (1,200 defect reports per annum), the study estimates a Year-0 investment of **₱185,944**, recurring annual costs of **₱31,403**, and gross annual benefits of **₱165,880**. This yields a five-year **Net Present Value (NPV) of ₱323,830**, a **Benefit–Cost Ratio (BCR) of 2.06**, a **five-year ROI of 106.2%**, an **Internal Rate of Return (IRR) of approximately 67%**, and a **simple payback period of 16.6 months** (18.9 months discounted). The equivalent annual cost of operating the system is **₱80,455**, or **₱67.05 per defect report processed** and **₱16.09 per registered campus user per year**.

Sensitivity analysis establishes that the investment remains economically justified (NPV > 0) so long as realized benefits exceed **48.5%** of the base-case estimate, or costs do not exceed **206%** of the base-case estimate. The analysis is, however, materially sensitive to two inputs — the shadow price assigned to institutional labor time and the assumed reduction in corrective maintenance expenditure — and the study identifies both as priority targets for post-implementation empirical validation. A comparative make-versus-buy analysis further finds the in-house system's five-year TCO to be **57.2% lower** than the lowest-priced comparable commercial CMMS subscription.

**Keywords:** return on investment, cost–benefit analysis, computerized maintenance management system, net present value, higher education information systems, property management

---

## TABLE OF CONTENTS

1. [Introduction and Rationale](#1-introduction-and-rationale)
2. [Objectives of the Study](#2-objectives-of-the-study)
3. [Scope and Delimitations](#3-scope-and-delimitations)
4. [Review of Related Literature](#4-review-of-related-literature)
5. [Theoretical and Methodological Framework](#5-theoretical-and-methodological-framework)
6. [Assumptions and Parameters](#6-assumptions-and-parameters)
7. [Cost Analysis](#7-cost-analysis)
8. [Benefit Analysis](#8-benefit-analysis)
9. [ROI Computation and Financial Indicators](#9-roi-computation-and-financial-indicators)
10. [Scenario Analysis](#10-scenario-analysis)
11. [Sensitivity and Break-Even Analysis](#11-sensitivity-and-break-even-analysis)
12. [Alternative Analysis: Make versus Buy](#12-alternative-analysis-make-versus-buy)
13. [Non-Monetized (Intangible) Benefits](#13-non-monetized-intangible-benefits)
14. [Risk Assessment](#14-risk-assessment)
15. [Limitations of the Study](#15-limitations-of-the-study)
16. [Conclusions](#16-conclusions)
17. [Recommendations](#17-recommendations)
18. [References](#18-references)
19. [Appendices](#19-appendices)

---

## 1. INTRODUCTION AND RATIONALE

The Property Management Office (PMO) of Batangas Eastern Colleges is institutionally accountable for the serviceability of campus equipment and facilities. Prior to the deployment of the system under evaluation, the reporting and resolution of defective equipment was conducted through a manual, paper-mediated workflow: a reporter (student, faculty member, or staff member) physically transmitted a defect report to the PMO; the office logged the report in a register; work was verbally or manually assigned to a technician; and status information was available only by direct inquiry.

Such workflows exhibit three characteristic economic inefficiencies documented in the maintenance management literature: (i) **transaction cost** — the labor time consumed by reporting, routing, and status-chasing, none of which produces maintenance output; (ii) **information loss** — the absence of a durable, queryable maintenance history, which prevents evidence-based planning and preventive intervention; and (iii) **response latency** — extended mean time to repair (MTTR), which prolongs equipment unavailability and accelerates asset deterioration.

The BEC Web-Based Defective Equipment Reporting Management System was developed to address these inefficiencies through a single official digital channel spanning the full lifecycle of a defect report — from submission through PMO receipt, approval, technician assignment and acceptance, repair execution, verification, and reporter satisfaction confirmation — with automated notification at every state transition.

Whereas the functional adequacy of the system has been established through system testing and user acceptance procedures documented elsewhere, its **economic justification** has not previously been established. This paper supplies that evaluation. The rationale is threefold: (a) to demonstrate to institutional decision-makers that continued operation and maintenance of the system is a defensible use of institutional resources; (b) to establish a quantitative baseline against which actual post-implementation performance can be measured; and (c) to satisfy the analytical requirement, standard in capstone research of this type, that a proposed information system be evaluated not only for what it does but for what it is worth.

---

## 2. OBJECTIVES OF THE STUDY

### 2.1 General Objective

To determine, through a formal discounted cost–benefit analysis, whether the BEC Web-Based Defective Equipment Reporting Management System constitutes an economically justified investment for Batangas Eastern Colleges over a five-year operating horizon.

### 2.2 Specific Objectives

1. To enumerate and quantify the total cost of ownership (TCO) of the system, disaggregated into development, deployment, and recurring operational cost categories.
2. To identify, quantify, and monetize the benefit streams attributable to the system, distinguishing clearly between monetizable and non-monetizable (intangible) benefits.
3. To compute the standard indicators of investment worth: Net Present Value (NPV), Benefit–Cost Ratio (BCR), Return on Investment (ROI), Internal Rate of Return (IRR), and payback period.
4. To test the robustness of the resulting conclusion through scenario analysis (pessimistic, base, optimistic) and one-way sensitivity analysis on each principal input parameter.
5. To determine the break-even thresholds at which the investment ceases to be economically justified.
6. To compare the in-house system against the commercial CMMS alternative on a total-cost-of-ownership basis.
7. To document the assumptions requiring empirical validation, and to specify the instruments by which that validation should be conducted post-implementation.

---

## 3. SCOPE AND DELIMITATIONS

### 3.1 Scope

The analysis covers the complete system as deployed: the public landing page and ticket tracker, the reporter portal, the PMO administrative suite (dashboard, analytics, defect review, preventive maintenance, inventory, user directory, backup and recovery, branded exports), and the technician progressive web application. It encompasses all direct costs of building, deploying, and operating the system, and all benefits accruing to the institution that can be traced to the system's operation.

### 3.2 Delimitations

The following are **excluded** from the quantified analysis, with justification:

| Excluded item | Justification for exclusion |
|---|---|
| Value of instructional time lost to unavailable equipment | Not reliably monetizable without institutional data on class disruption; treated as an intangible benefit (§13) |
| Reputational and accreditation value | Inherently non-monetizable; treated as intangible (§13) |
| Pedagogical value to the developing student team | An externality accruing to the developers, not the institution |
| Existing hardware (PCs, technicians' personal smartphones) | Pre-existing institutional and personal assets; no incremental acquisition is required by the system |
| Cost of institutional email accounts | The institution's Google Workspace for Education tenancy is a pre-existing, separately-justified cost |
| Second-order effects on enrollment or retention | Speculative and not causally attributable |

The analysis adopts an **institutional perspective** (costs and benefits accruing to Batangas Eastern Colleges as an entity), not a social perspective. It is conducted on an **incremental basis**: only costs and benefits that differ between the "with system" and "without system" (manual baseline) states are counted.

---

## 4. REVIEW OF RELATED LITERATURE

### 4.1 Empirical Findings on CMMS Return on Investment

The economic effects of computerized maintenance management systems are well documented in the industrial maintenance literature, though the majority of published evidence derives from manufacturing and facilities-management contexts rather than higher education. Four findings are directly relevant to the present study.

**Maintenance cost reduction.** Organizations implementing a CMMS commonly report a **10–30% reduction in overall maintenance costs**, attributable to improved scheduling, better labor utilization, and a reduction in unplanned breakdowns; leading implementations achieve reductions of **18–25%** (eWorkOrders, 2026; Oxmaint, 2026). This finding underpins benefit stream B5 in the present analysis, though the present study applies a substantial conservative haircut for reasons stated in §8.6.

**Downtime reduction.** The Aberdeen Group CMMS Benchmark Report documents an average **27% reduction in equipment downtime** for organizations operating a CMMS relative to those without structured maintenance management (cited in eWorkOrders, 2026). More aggressive predictive-maintenance implementations report reductions of 30–50%. The present study treats downtime reduction as an intangible benefit (§13) rather than monetizing it.

**Asset life extension.** Systematic preventive maintenance, of the kind the system's preventive maintenance module enables, is associated with **20–40% longer useful asset life** relative to run-to-failure operation, with corresponding deferral of capital expenditure (Oxmaint, 2026).

**Payback horizon.** Most organizations achieve positive ROI **within 12–18 months** of CMMS implementation (LLumin, 2026; Oxmaint, 2026). The base-case payback period computed in §9 (16.6 months) falls within this documented range, which provides external plausibility support for the present estimates.

### 4.2 Critical Appraisal of the Literature

Two methodological cautions apply to the figures above. First, a substantial proportion of the published CMMS ROI literature is **vendor-authored**, and therefore subject to a selection bias favoring successful implementations and an incentive bias favoring generous benefit estimates. Second, the reference populations are predominantly capital-intensive manufacturing operations with high downtime costs, whereas an educational institution's equipment portfolio is lower in unit value and its downtime cost is largely non-financial. Both cautions argue for the deliberate conservatism adopted in §8.6, where the present study applies a maintenance-expenditure reduction rate of 5% — well below the lower bound of the 10–30% literature range.

### 4.3 Cost Benchmarks for Comparable Systems

Commercial CMMS platforms in 2026 are priced predominantly on a per-named-user, per-month subscription basis. Published list prices are summarized in Table 4.1.

**Table 4.1 — Commercial CMMS List Pricing, 2026**

| Vendor | Entry tier | Mid tier | Notes |
|---|---|---|---|
| Limble | US$28/user/month (annual) | US$33/user/month (monthly) | Lowest-priced comparable platform |
| UpKeep | US$20/user/month (Lite) | US$45–75/user/month | Lite tier is functionally restricted |
| Fiix | Free tier (limited) | US$45–75/user/month | |
| eMaint | US$69/user/month (max 3 users) | US$85/user/month (min 3 users) | |
| **Market range** | **US$28–50/user/month** | **US$50–150/user/month** | (Fabrico, 2026; ITQlick, 2026) |

These benchmarks are applied in the make-versus-buy analysis of §12.

---

## 5. THEORETICAL AND METHODOLOGICAL FRAMEWORK

### 5.1 Analytical Framework

The study applies the standard **discounted cash-flow (DCF) cost–benefit framework** to a five-year evaluation horizon. All costs and benefits are expressed in constant August 2026 pesos (real terms); consequently a **real discount rate** is applied and no separate inflation adjustment is made to the cash-flow streams.

### 5.2 Discount Rate

A discount rate of **10.0% per annum** is adopted. This is the **social discount rate prescribed by the National Economic and Development Authority (NEDA) Investment Coordination Committee** for the evaluation of Philippine public-sector projects, revised downward from 15% in 2016 (NEDA, 2016). Although Batangas Eastern Colleges is a private institution, the NEDA rate is adopted for three reasons: it is the standard analytical benchmark in Philippine project appraisal; it is conservative relative to prevailing real returns on low-risk institutional deposits; and its use makes the present analysis directly comparable with other Philippine institutional project evaluations. Sensitivity to this parameter is tested at 6% and 15% in §11.

### 5.3 Financial Indicators

The following indicators are computed. Let *B<sub>t</sub>* and *C<sub>t</sub>* denote gross benefits and gross costs in year *t*, *r* the discount rate, and *n* the evaluation horizon.

**(1) Net Present Value**

$$NPV = \sum_{t=0}^{n} \frac{B_t - C_t}{(1+r)^t}$$

Decision rule: accept if NPV > 0.

**(2) Benefit–Cost Ratio**

$$BCR = \frac{\sum_{t=0}^{n} B_t (1+r)^{-t}}{\sum_{t=0}^{n} C_t (1+r)^{-t}}$$

Decision rule: accept if BCR > 1.0.

**(3) Return on Investment (five-year, discounted)**

$$ROI = \frac{NPV}{PV(\text{Total Costs})} \times 100\%$$

This formulation expresses net gain as a percentage of the present value of all resources committed. It is preferred here over the simple accounting ROI because it accounts for the time value of money and for recurring costs, both of which a single-period ROI omits.

**(4) Internal Rate of Return** — the discount rate *r\** at which NPV = 0:

$$\sum_{t=0}^{n} \frac{B_t - C_t}{(1+r^*)^t} = 0$$

Decision rule: accept if IRR > the hurdle rate (10%).

**(5) Payback Period** — the elapsed time until cumulative net benefits equal the initial investment; reported on both an undiscounted and a discounted basis.

**(6) Equivalent Annual Cost (EAC)** — the level annual payment whose present value equals the total present value of costs:

$$EAC = \frac{PV(\text{Total Costs})}{A_{n,r}}, \quad \text{where } A_{n,r} = \frac{1-(1+r)^{-n}}{r}$$

For *n* = 5 and *r* = 10%, the annuity factor *A* = **3.790787**. This factor is used throughout the analysis.

### 5.4 Valuation Method for Labor Time

Benefits arising from time savings are monetized using the **opportunity cost of labor**, valued at the fully-loaded hourly wage of the affected personnel class. Where the affected party is a student — whose time has no market wage — the **regional statutory minimum wage is applied as a shadow price**, following standard practice in the shadow-pricing of non-market time. This is a deliberately conservative treatment; the sensitivity analysis in §11 additionally reports results with all labor-time benefits excluded entirely, so that the reader may judge the conclusion independently of this valuation choice.

### 5.5 Treatment of Development Cost

The system was developed as an academic capstone project, and the institution therefore incurred **no cash outlay** for development labor. Two treatments are possible. Recording development cost as zero would produce a spuriously favorable ROI and would misrepresent the cost of replicating or sustaining the system. This study therefore adopts the **shadow-priced (opportunity-cost) treatment**: development labor is valued at prevailing market rates for equivalent professional work, as though the institution had procured it. This treatment is more conservative, more informative for institutional decision-making, and more defensible analytically. It is noted explicitly that ₱185,944 of the Year-0 cost is an **imputed, non-cash cost**; the corresponding cash-basis figures are reported in §9.5.

---

## 6. ASSUMPTIONS AND PARAMETERS

### 6.1 Institutional Parameters

**Table 6.1 — Institutional Baseline Assumptions**

| # | Parameter | Value | Basis / Source | Confidence |
|---|---|---|---|---|
| A1 | Registered campus population | 5,000 users | Institutional enrollment and personnel complement | Medium |
| A2 | Annual defect report volume | 1,200 reports/yr (100/month) | Estimated; within system's tested comfortable operating range (≤2,000) | **Low — requires validation** |
| A3 | PMO personnel complement | 2 administrative + 3 technicians | Institutional structure | Medium |
| A4 | Annual equipment repair & replacement expenditure | ₱1,200,000 | Estimated from institutional scale | **Low — requires validation** |
| A5 | System useful life / evaluation horizon | 5 years | Standard for institutional web applications | High |

### 6.2 Labor Rate Parameters

Hourly rates are derived on a 22-working-day month at 8 hours per day (176 hours/month).

**Table 6.2 — Labor Rate Assumptions**

| # | Personnel class | Monthly basis | Hourly rate | Source |
|---|---|---|---|---|
| L1 | Reporter (blended student/faculty/staff) | — | **₱100/hr** | Shadow price; between the CALABARZON minimum wage (₱600/day ÷ 8 = ₱75/hr) and staff rates (NWPC, 2026) |
| L2 | PMO administrative staff | ₱20,240 | **₱115/hr** | Philippine administrative-officer benchmarks, private-institution adjusted (ERI SalaryExpert, 2026) |
| L3 | Maintenance technician | ₱15,840 | **₱90/hr** | CALABARZON non-agricultural minimum wage plus mandated benefits (NWPC, 2026) |
| L4 | Web developer (for imputed development and maintenance labor) | ₱29,920 | **₱170/hr** | Philippine junior/mid web-developer market rate, ₱27,000–43,000/month (Indeed, 2026; Jobstreet, 2026) |

**Note on L1.** Wage Order No. IVA-22 sets the CALABARZON non-agricultural daily minimum wage at up to ₱600 effective 01 April 2026, varying by locality tier (NWPC, 2026). The ₱75/hr floor derived from this rate is applied as the shadow price for student time; the ₱100/hr blended figure reflects the mixed composition of the reporter population.

### 6.3 Technical and Pricing Parameters

**Table 6.3 — Technical Service Cost Assumptions**

| # | Parameter | Value | Source |
|---|---|---|---|
| T1 | Supabase PostgreSQL, Pro plan | US$25/month = US$300/yr | Supabase published pricing, 2026 |
| T2 | Application hosting (VPS with `pdo_pgsql`) | US$6/month = US$72/yr | Market rate for entry-tier VPS |
| T3 | Domain registration | ₱900/yr | Philippine registrar market rate |
| T4 | Google Gemini API — model `gemini-flash-lite-latest`, free tier | US$0 within published request ceilings | Google AI Studio published tier terms, 2026 |
| T5 | Estimated AI assistant usage | 12,000 requests/yr ≈ 33/day @ ~2,500 input + 250 output tokens | 3,000 conversations × 4 turns; engineering estimate |
| T6 | USD–PHP exchange rate | ₱61.02 = US$1.00 | August 2026 monthly average (exchange-rates.org, 2026) |

**Note on T4.** The system's three "Becca" assistant instances (`chat_proxy.php`, `admin_chat_proxy.php`, `technician_chat_proxy.php`) share a single model client (`includes/ai_client.php`) calling Gemini Flash-Lite on the free tier. At the stated volume — approximately 33 requests per day against a daily ceiling an order of magnitude higher — usage sits within the free allowance, so the marginal cost is **US$0/yr = ₱0**.

**Note on the admissibility of a free tier.** Table 7.2 specifies the *paid* Supabase plan precisely because free-tier limitations were judged unacceptable for a production system; intellectual consistency requires explaining why the opposite conclusion is reached here. The two constraints differ in kind, not degree. Supabase's free tier suspends a project after one week of inactivity — an availability failure of the entire system, with no graceful degradation available. The Gemini free tier's binding constraint is a per-minute request ceiling, and the assistant is deliberately engineered to absorb it: all three proxies fall back to a built-in rules-and-live-data responder rather than returning an error, so the failure mode is a temporarily less conversational assistant, not an outage. The contingency in which the free tier is withdrawn altogether is quantified as sensitivity test S11 (§11.1).

### 6.4 Process Time Assumptions (Baseline versus System)

**Table 6.4 — Estimated Per-Report Process Times**

| Activity | Manual baseline | With system | Saving |
|---|---|---|---|
| Reporter: prepare and transmit report (incl. travel to PMO) | 25 min | 5 min | **20 min** |
| PMO: log, route, assign, respond to status inquiries | 22 min | 7 min | **15 min** |
| PMO: compile periodic management reports | 8 hrs/month | 1 hr/month | **7 hrs/month** |
| Technician: repeat site visit due to inadequate diagnosis | 12% of jobs × 45 min | — | **45 min on 12% of jobs** |

These are engineering estimates informed by the workflow documented in the system consultation report. They are the **single most influential set of unvalidated assumptions in this study** and are treated as such throughout §10 and §11.

---

## 7. COST ANALYSIS

### 7.1 Year-0 Investment Costs

**Table 7.1 — Year-0 (Deployment) Costs**

| Code | Cost item | Basis | Amount |
|---|---|---|---:|
| C1 | Development labor (imputed) | 900 person-hours × ₱170/hr | ₱153,000 |
| C2 | Deployment, configuration, and data migration | 60 hrs × ₱170/hr | ₱10,200 |
| C3 | User training and change management | 12 participants × 3 hrs × ₱115 + 10 preparation hrs × ₱170 | ₱5,840 |
| C4 | Hardware acquisition | Existing assets utilized; no incremental acquisition | ₱0 |
| — | **Subtotal** | | **₱169,040** |
| C5 | Contingency provision | 10% of subtotal | ₱16,904 |
| | **TOTAL YEAR-0 INVESTMENT** | | **₱185,944** |

Of this total, **₱185,944 is imputed rather than cash-settled**, since development, deployment, and training were performed by the capstone team. See §9.5 for cash-basis indicators.

### 7.2 Recurring Annual Operating Costs

**Table 7.2 — Recurring Annual Costs (Years 1–5)**

| Code | Cost item | Basis | Amount |
|---|---|---|---:|
| R1 | Supabase PostgreSQL (Pro plan) | US$300 × ₱61.02 | ₱18,306 |
| R2 | Application hosting (VPS) | US$72 × ₱61.02 | ₱4,393 |
| R3 | Domain registration and renewal | | ₱900 |
| R4 | Google Gemini API (AI assistant) | Free tier, within request ceilings | ₱0 |
| R5 | Corrective and adaptive maintenance | 40 hrs/yr × ₱170/hr | ₱6,800 |
| R6 | Refresher training and new-staff onboarding | | ₱1,000 |
| R7 | Institutional email (Google Workspace for Education) | Pre-existing institutional tenancy | ₱0 |
| | **TOTAL RECURRING ANNUAL COST** | | **₱31,403** |

**Note on R1.** The Supabase Pro plan is specified rather than the free tier because free-tier projects are suspended after one week of inactivity and are constrained to 500 MB of database storage — neither condition being acceptable for a production institutional system.

### 7.3 Present Value of Total Costs

$$PV(C) = 185{,}944 + (31{,}403 \times 3.790787) = 185{,}944 + 119{,}042 = \boxed{₱304{,}986}$$

---

## 8. BENEFIT ANALYSIS

Five benefit streams are quantified. Each is defined so as to be **mutually exclusive** of the others, in order to preclude double-counting. Benefits attributable to reduced status inquiries are consolidated within B2 rather than counted separately.

### 8.1 B1 — Reporter Transaction-Time Savings

Under the manual baseline, a reporter must physically deliver a report to the PMO. The system permits submission from any device, with photographic evidence attached and automatic confirmation.

$$B_1 = 1{,}200 \text{ reports} \times \frac{20 \text{ min}}{60} \times ₱100/\text{hr} = 400 \text{ hrs} \times ₱100 = \boxed{₱40{,}000}$$

### 8.2 B2 — PMO Administrative Processing Savings

Comprises (a) per-report logging, routing, assignment, and status-response labor, and (b) periodic management report compilation, which the system's filtered export function reduces from a manual collation task to a parameterized query.

| Component | Computation | Amount |
|---|---|---:|
| Per-report processing | 1,200 × (15/60) = 300 hrs × ₱115 | ₱34,500 |
| Periodic report compilation | 7 hrs/month × 12 = 84 hrs × ₱115 | ₱9,660 |
| **Total B2** | | **₱44,160** |

### 8.3 B3 — Technician Productivity Gains

Photographic evidence at intake, specialization-based smart assignment, and complete equipment history reduce the incidence of repeat site visits arising from inadequate initial diagnosis or mismatched technician skill.

$$B_3 = 1{,}200 \times 0.12 \times \frac{45}{60} \times ₱90 = 144 \text{ jobs} \times 0.75 \text{ hrs} \times ₱90 = \boxed{₱9{,}720}$$

### 8.4 B4 — Status-Inquiry Deflection

The public ticket tracker and automated notification matrix eliminate the majority of "what is the status of my report?" inquiries. **This benefit is deliberately consolidated within B2** rather than counted as a separate stream, to avoid double-counting PMO staff time. It is recorded here for completeness and to document the exclusion.

**Quantified separately: ₱0** (subsumed in B2).

### 8.5 B5 — Consumables and Records Management Savings

| Component | Computation | Amount |
|---|---|---:|
| Forms and printing | 1,200 reports × 3 pages × ₱2 | ₱7,200 |
| Registers, folders, toner, physical storage | Estimated | ₱4,800 |
| **Total B5** | | **₱12,000** |

### 8.6 B6 — Reduction in Corrective Maintenance Expenditure

The literature reviewed in §4.1 reports maintenance cost reductions of 10–30% following CMMS implementation, and asset life extension of 20–40% under systematic preventive maintenance. For the reasons set out in §4.2 — vendor-authored source bias, and the dissimilarity between industrial reference populations and an educational equipment portfolio — this study applies a **rate of 5%**, which is **half the lower bound** of the published range.

$$B_6 = ₱1{,}200{,}000 \times 0.05 = \boxed{₱60{,}000}$$

**This is the single largest benefit line and rests on the least well-validated institutional parameter (A4).** Section 11 accordingly reports the result with this stream removed entirely.

### 8.7 Summary of Quantified Benefits

**Table 8.1 — Annual Benefit Summary (Base Case)**

| Code | Benefit stream | Amount | Share |
|---|---|---:|---:|
| B1 | Reporter transaction-time savings | ₱40,000 | 24.1% |
| B2 | PMO administrative processing savings | ₱44,160 | 26.6% |
| B3 | Technician productivity gains | ₱9,720 | 5.9% |
| B4 | Status-inquiry deflection | ₱0 (in B2) | — |
| B5 | Consumables and records management | ₱12,000 | 7.2% |
| B6 | Reduction in corrective maintenance expenditure | ₱60,000 | 36.2% |
| | **TOTAL GROSS ANNUAL BENEFITS** | **₱165,880** | **100.0%** |

$$PV(B) = 165{,}880 \times 3.790787 = \boxed{₱628{,}816}$$

---

## 9. ROI COMPUTATION AND FINANCIAL INDICATORS

### 9.1 Cash-Flow Schedule

**Table 9.1 — Five-Year Cash-Flow Schedule, Base Case**

| Year | Gross benefits | Gross costs | Net cash flow | Discount factor (10%) | Present value | Cumulative PV |
|---|---:|---:|---:|---:|---:|---:|
| 0 | ₱0 | ₱185,944 | (₱185,944) | 1.0000 | (₱185,944) | (₱185,944) |
| 1 | ₱165,880 | ₱31,403 | ₱134,477 | 0.9091 | ₱122,253 | (₱63,691) |
| 2 | ₱165,880 | ₱31,403 | ₱134,477 | 0.8264 | ₱111,132 | ₱47,441 |
| 3 | ₱165,880 | ₱31,403 | ₱134,477 | 0.7513 | ₱101,033 | ₱148,473 |
| 4 | ₱165,880 | ₱31,403 | ₱134,477 | 0.6830 | ₱91,848 | ₱240,321 |
| 5 | ₱165,880 | ₱31,403 | ₱134,477 | 0.6209 | ₱83,497 | **₱323,818** |
| **Total** | **₱829,400** | **₱342,959** | **₱486,441** | | | |

The Year-5 cumulative differs from the NPV in §9.2 by ₱12 because the discount factors above are rounded to four decimal places; the §9.2 figure, computed from the unrounded annuity factor, is the one cited throughout.

### 9.2 Computation of Indicators

**Net Present Value**

$$NPV = PV(B) - PV(C) = 628{,}816 - 304{,}986 = \boxed{₱323{,}830}$$

**Benefit–Cost Ratio**

$$BCR = \frac{628{,}816}{304{,}986} = \boxed{2.06}$$

**Return on Investment (five-year, discounted)**

$$ROI = \frac{323{,}830}{304{,}986} \times 100\% = \boxed{106.2\%}$$

**Internal Rate of Return.** Solving *A*<sub>5,r\*</sub> = 185,944 ÷ 134,477 = 1.3827 yields:

$$IRR \approx \boxed{67\%}$$

**Payback Period.** Undiscounted:

$$PP = \frac{185{,}944}{134{,}477} = 1.38 \text{ years} = \boxed{16.6 \text{ months}}$$

Discounted (from Table 9.1, cumulative PV crosses zero during Year 2):

$$PP_{disc} = 1 + \frac{63{,}691}{111{,}132} = 1.57 \text{ years} = \boxed{18.9 \text{ months}}$$

**Equivalent Annual Cost**

$$EAC = \frac{304{,}986}{3.790787} = \boxed{₱80{,}455/\text{year}}$$

### 9.3 Summary of Results

**Table 9.2 — Base-Case Financial Indicators**

| Indicator | Value | Decision rule | Verdict |
|---|---:|---|---|
| Net Present Value (5 yr, 10%) | **₱323,830** | NPV > 0 | **PASS** |
| Benefit–Cost Ratio | **2.06** | BCR > 1.0 | **PASS** |
| Return on Investment (5 yr) | **106.2%** | ROI > 0% | **PASS** |
| Internal Rate of Return | **≈67%** | IRR > 10% | **PASS** |
| Payback period (simple) | **16.6 months** | < 5 yr horizon | **PASS** |
| Payback period (discounted) | **18.9 months** | < 5 yr horizon | **PASS** |

### 9.4 Derived Unit Economics

**Table 9.3 — Unit Cost Indicators**

| Indicator | Computation | Value |
|---|---|---:|
| Equivalent annual cost of ownership | PV(C) ÷ 3.790787 | ₱80,455/yr |
| Cost per defect report processed | ₱80,455 ÷ 1,200 | **₱67.05** |
| Cost per registered campus user per year | ₱80,455 ÷ 5,000 | **₱16.09** |
| Net benefit per defect report processed | (₱165,880 − ₱31,403) ÷ 1,200 | **₱112.06** |

### 9.5 Cash-Basis Restatement

Because ₱185,944 of Year-0 cost is imputed rather than cash-settled (§5.5), the institution's actual cash position differs materially. On a cash basis — recognizing only recurring costs R1–R6 — the indicators are:

| Indicator | Cash basis |
|---|---:|
| PV of cash costs | ₱119,042 |
| NPV | ₱509,774 |
| BCR | 5.28 |
| ROI (5 yr) | 428.2% |
| Payback | Immediate (Year 1 net positive) |

**The shadow-priced figures in §9.3 are the analytically correct basis for the decision** and are the figures cited throughout this paper. The cash-basis restatement is provided solely to document the institution's actual outlay position.

---

## 10. SCENARIO ANALYSIS

Three scenarios are constructed by varying the four principal benefit drivers. Cost parameters are held constant across scenarios (except for a modest hosting allowance in the optimistic case reflecting higher transaction volume), since costs are determined by the build rather than by usage; cost uncertainty is treated separately in §11. The optimistic allowance is attributed wholly to hosting: even at the optimistic volume, assistant traffic stays inside the free-tier ceilings of T4, and retaining the full allowance rather than reducing it is the conservative treatment.

**Table 10.1 — Scenario Parameter Definitions**

| Driver | Pessimistic | Base | Optimistic |
|---|---:|---:|---:|
| Annual report volume | 800 | 1,200 | 1,800 |
| Reporter minutes saved per report | 12 | 20 | 28 |
| PMO minutes saved per report | 10 | 15 | 20 |
| Repeat-visit avoidance rate | 8% | 12% | 16% |
| Maintenance expenditure reduction rate | 2.5% | 5.0% | 10.0% |
| Recurring annual cost | ₱31,403 | ₱31,403 | ₱34,703 |

**Table 10.2 — Scenario Benefit Streams (per annum)**

| Stream | Pessimistic | Base | Optimistic |
|---|---:|---:|---:|
| B1 — Reporter time | ₱16,000 | ₱40,000 | ₱84,000 |
| B2 — PMO processing | ₱22,233 | ₱44,160 | ₱80,040 |
| B3 — Technician productivity | ₱4,320 | ₱9,720 | ₱19,440 |
| B5 — Consumables | ₱8,000 | ₱12,000 | ₱18,000 |
| B6 — Maintenance expenditure | ₱30,000 | ₱60,000 | ₱120,000 |
| **Total gross benefits** | **₱80,553** | **₱165,880** | **₱321,480** |

**Table 10.3 — Scenario Results**

| Indicator | Pessimistic | Base | Optimistic |
|---|---:|---:|---:|
| PV of benefits | ₱305,359 | ₱628,816 | ₱1,218,662 |
| PV of costs | ₱304,986 | ₱304,986 | ₱317,496 |
| **NPV** | **₱373** | **₱323,830** | **₱901,166** |
| **BCR** | **1.00** | **2.06** | **3.84** |
| **ROI (5 yr)** | **0.1%** | **106.2%** | **283.8%** |
| **IRR** | **≈10.1%** | **≈67%** | **≈153%** |
| **Payback (simple)** | **45.4 months** | **16.6 months** | **7.8 months** |
| **Verdict** | Marginally justified | **Justified** | Strongly justified |

### 10.1 Interpretation

The pessimistic scenario — which simultaneously assumes a one-third lower report volume, 40% smaller per-transaction time savings, and a maintenance-expenditure reduction of only 2.5% — produces an NPV of ₱373 and an IRR of 10.1%. This sits **fractionally above the 10% hurdle rate**, which is to say essentially at break-even: the project recovers its full shadow-priced investment within 46 months, but with no meaningful margin.

The pessimistic case should be read as break-even rather than as a positive result. An NPV of ₱373 on a present-value cost base of ₱304,986 is a margin of roughly one-tenth of one percent — far inside the precision of the underlying estimates, and not a figure on which any decision should rest. What the scenario establishes is the **bound**, not a return: under a conjunction of adverse assumptions that would be unusual in practice, the project neither gains nor loses materially.

The practical significance is that the investment is **not fragile**. The downside is bounded at approximately break-even while the upside reaches +₱901,166, and the asymmetry of that distribution is strongly favorable. It is worth noting that this scenario cleared the hurdle rate only after the AI service moved to a zero-marginal-cost tier (T4); under the previous paid-API assumption the same scenario returned −₱10,037 at an IRR of 7.9%. A ₱2,746 annual line item was therefore sufficient to move the pessimistic case across the hurdle rate — which is itself evidence of how narrow that scenario's margin is, and a caution against treating it as a positive finding.

---

## 11. SENSITIVITY AND BREAK-EVEN ANALYSIS

### 11.1 One-Way Sensitivity Analysis

Each parameter is varied independently, holding all others at base-case values.

**Table 11.1 — One-Way Sensitivity Results**

| # | Parameter varied | Variation tested | NPV | BCR | Verdict |
|---|---|---|---:|---:|---|
| S0 | *(Base case)* | — | ₱323,830 | 2.06 | Justified |
| S1 | Discount rate | 6% (lower) | ₱380,522 | 2.20 | Justified |
| S2 | Discount rate | 15% (higher) | ₱264,844 | 1.91 | Justified |
| S3 | All benefits | −25% | ₱166,626 | 1.55 | Justified |
| S4 | All benefits | −40% | ₱72,303 | 1.24 | Justified |
| S5 | All benefits | −50% | ₱9,422 | 1.03 | Justified (break-even at −51.5%) |
| S6 | All costs | +50% | ₱171,337 | 1.37 | Justified |
| S7 | All costs | +100% | ₱18,844 | 1.03 | Justified (break-even at +106.2%) |
| S8 | Report volume | 800/yr (−33%) | ₱202,248 | 1.66 | Justified |
| S9 | **All labor-time benefits excluded** (B1+B2+B3 = ₱0) | Removes ₱93,880/yr | **(₱32,049)** | **0.89** | **Not justified** |
| S10 | **Maintenance-expenditure benefit excluded** (B6 = ₱0) | Removes ₱60,000/yr | **₱96,382** | **1.32** | Justified |
| S11 | **AI free tier withdrawn** — API reverts to paid | Adds ₱2,746/yr | **₱313,420** | **1.99** | Justified |

**Note on S11.** The free tier assumed in T4 is a commercial term the institution does not control and which the provider may withdraw. S11 restores a paid API line at ₱2,746/yr — the cost of the higher-priced model this system previously used, and therefore a conservative upper bound on what an equivalent paid tier would cost. The investment remains justified at NPV ₱313,420 and BCR 1.99. **The conclusion of this study does not depend on the free tier's continued availability.**

### 11.2 Interpretation of Sensitivity Results

Three findings warrant emphasis.

**First, the conclusion is robust to discount-rate choice.** Varying the discount rate across the full plausible range (6%–15%) changes NPV by less than ±18% and does not alter the decision. The choice of the NEDA rate is therefore not driving the result.

**Second, the conclusion is robust to substantial parameter error.** The investment remains justified with benefits overstated by up to 51.5%, or costs understated by up to 106%. Given that the base-case benefit estimates already incorporate deliberate conservatism (§8.6), this margin is comfortable. It is also robust to withdrawal of the AI free tier (S11), which the institution does not control.

**Third — and this is the study's principal analytical caveat — the conclusion is materially dependent on the monetization of institutional labor time.** Test S9 removes all three labor-time benefit streams and yields a negative NPV (−₱32,049, BCR 0.89). An evaluator who rejects the valuation of saved staff and student time as an economic benefit — on the grounds, for instance, that such time is not actually redeployed to productive alternative use — would not find the investment justified on the remaining quantified streams alone. Note that eliminating the API cost narrowed but did not close this gap: S9 improved from −₱42,459 to −₱32,049 and remains decisively negative. **No reduction in operating cost of this order rescues the analysis from the labor-valuation objection**, which is a question of benefit methodology rather than of cost.

This objection is analytically legitimate and is stated here explicitly rather than obscured. Two counter-considerations apply. (a) The PMO component of the labor saving (B2, ₱44,160) is the most defensible of the three, since PMO staff time released from clerical logging is demonstrably redeployable to maintenance supervision. (b) The intangible benefits catalogued in §13 — which include accountability, auditability, and institutional data assets — are not counted anywhere in the quantified analysis and would offset a substantial part of the S9 shortfall if monetized.

Conversely, test S10 shows that removing the largest and least-validated single line (B6, maintenance expenditure reduction) still leaves the project justified at BCR 1.32. The analysis is therefore **not** dependent on the maintenance-reduction assumption, which is the more reassuring of the two findings.

### 11.3 Break-Even Analysis

**Table 11.2 — Break-Even Thresholds**

| Break-even condition | Threshold | Base case | Margin of safety |
|---|---:|---:|---:|
| Minimum annual gross benefit for NPV = 0 | ₱80,455 | ₱165,880 | **51.5%** |
| Maximum PV of total cost for NPV = 0 | ₱628,816 | ₱304,986 | **106.2%** |
| Minimum annual report volume (with B6 retained) | 135 reports | 1,200 | **88.8%** |
| Minimum annual report volume (with B6 excluded) | 883 reports | 1,200 | **26.4%** |
| Maximum discount rate for NPV = 0 (IRR) | ≈67% | 10% | **57 pp** |

The break-even annual benefit of ₱80,455 is, by construction, identical to the equivalent annual cost computed in §9.4. The investment is justified provided the system delivers annual benefits of at least **₱80,455 — equivalently, ₱67.05 per report at base volume.**

---

## 12. ALTERNATIVE ANALYSIS: MAKE VERSUS BUY

The relevant counterfactual is not only "no system" but also "commercially licensed system." This section evaluates the in-house build against procurement of a commercial CMMS meeting equivalent functional requirements.

### 12.1 Assumptions

- Named users required: **8** (2 PMO administrators, 3 technicians, 3 reserve/supervisory seats).
- Reporter self-service is assumed unlicensed (requester portals are typically included), consistent with vendor practice.
- One-time implementation, configuration, and data-migration cost: **US$1,500 = ₱91,530**.
- Vendor pricing per Table 4.1.

### 12.2 Comparative Five-Year Total Cost of Ownership

**Table 12.1 — Five-Year TCO Comparison (Present Value, 10%)**

| Option | One-time | Annual | PV of annual | **Five-year PV TCO** |
|---|---:|---:|---:|---:|
| **In-house system (this study)** | ₱185,944 | ₱31,403 | ₱119,042 | **₱304,986** |
| Commercial — entry tier (Limble, US$28/user/mo) | ₱91,530 | ₱163,968 | ₱621,568 | **₱713,098** |
| Commercial — mid tier (US$75/user/mo) | ₱91,530 | ₱439,344 | ₱1,665,460 | **₱1,756,990** |

### 12.3 Findings

The in-house system's five-year total cost of ownership is **₱408,112 (57.2%) lower** than the lowest-priced comparable commercial subscription, and **₱1,452,004 (82.6%) lower** than a mid-tier commercial platform. On a cash basis — excluding the imputed development cost — the differential widens to **₱594,056 (83.3%)** against the entry tier.

Two qualifications apply. Commercial platforms deliver vendor-provided support, guaranteed uptime, and a maintenance roadmap, none of which the in-house system carries; these are real values not captured in the cost comparison. Conversely, the in-house system is precisely fitted to BEC's workflow, official forms, and institutional identity, and the institution retains full ownership of both code and data — advantages a commercial subscription does not confer. **The cost differential is nonetheless of a magnitude (a factor of 2.3 to 5.8) that is unlikely to be reversed by these considerations.**

---

## 13. NON-MONETIZED (INTANGIBLE) BENEFITS

The following benefits are attributable to the system but are deliberately excluded from the quantified analysis, either because they cannot be reliably monetized or because doing so would require speculative assumptions. Their exclusion means the quantified NPV of ₱313,420 should be regarded as a **lower bound** on the system's institutional value.

**Table 13.1 — Intangible Benefit Register**

| # | Benefit | System feature responsible | Assessed magnitude |
|---|---|---|---|
| I1 | Accountability and auditability of maintenance decisions | Immutable audit log; timestamped state transitions | High |
| I2 | Elimination of anonymous and fraudulent reporting | BEC directory verification at intake | High |
| I3 | Institutional maintenance data asset for planning and budgeting | Analytics module; filtered multi-format exports | High |
| I4 | Reduction of instructional disruption from equipment downtime | Reduced MTTR; SLA escalation | Medium–High |
| I5 | Reporter trust and service transparency | Event-driven tracker; full notification matrix | Medium |
| I6 | Data recoverability and business continuity | Automated daily backups with 14-day rotation; restore module | Medium |
| I7 | Evidentiary support for accreditation and institutional review | Branded official exports; complete maintenance history | Medium |
| I8 | Equitable technician workload distribution | Smart assignment with computed availability | Medium |
| I9 | Reduction of interpersonal friction in status escalation | Rate-limited structured follow-up mechanism | Low–Medium |
| I10 | Institutional digital-transformation posture | System as a whole | Low (non-financial) |

Benefit I4 merits particular note. The literature reports a 27% average reduction in equipment downtime under CMMS operation (§4.1). Were the instructional value of that avoided downtime monetized, it would plausibly exceed all five quantified benefit streams combined. It is excluded here solely because a defensible peso value per hour of instructional disruption cannot be established without institutional study.

---

## 14. RISK ASSESSMENT

**Table 14.1 — Risk Register**

| # | Risk | Likelihood | Impact on ROI | Mitigation |
|---|---|---|---|---|
| K1 | **Low user adoption** — reporters continue using informal channels | Medium | Severe (all benefit streams scale with volume) | Mandate the system as the sole official channel; institutional communication campaign; retain the low-friction reporter flow |
| K2 | **Maintainer discontinuity** — capstone team graduates, leaving no maintainer | **High** | Severe | Transfer knowledge before turnover; maintain the documentation set; budget for a retained developer at the R5 rate |
| K3 | **Scalability ceiling** — report lists paginate client-side; page weight scales with row count (11 MB at 5,010 reports) | Medium | Moderate (degraded usability, not failure) | Implement server-side pagination before backlog exceeds ~2,000 open reports |
| K4 | **Vendor dependency** — Supabase pricing or policy change | Low | Low (R1 is 53.6% of recurring cost but only 21.7% of EAC) | PostgreSQL is portable; migration path to alternative hosts exists |
| K5 | **Email deliverability failure** — notifications filtered as spam | Medium | Moderate (undermines B2 and I5) | Configure SPF/DKIM/DMARC per `EMAIL_DELIVERABILITY.md`; migrate to institutional SMTP |
| K6 | **Benefit estimates prove optimistic** | Medium | Bounded — see §10 pessimistic scenario (NPV ₱373, essentially break-even) | Conduct the post-implementation measurement programme in §17 |
| K7 | **Exchange-rate depreciation** raises USD-denominated costs (R1, R2, R4) | Medium | Low — a 20% depreciation raises EAC by ₱5,089 (6.1%) | None required; magnitude is immaterial |
| K8 | **Security incident or data loss** | Low | Moderate–Severe | OTP administrator authentication; CSRF protection; rate limiting; automated backups; audit logging |

**K2 is the principal risk to sustained realization of the benefits computed in this study.** All five benefit streams are contingent on the system remaining operational and maintained; the recurring cost line R5 (₱6,800/yr) provides for only 40 hours of maintenance annually and presumes an available maintainer. This is addressed in Recommendation R4 (§17).

---

## 15. LIMITATIONS OF THE STUDY

The following limitations qualify the findings and should be considered by any reader relying on them.

1. **Ex-ante estimation.** The analysis is prospective. The process-time assumptions in Table 6.4 are engineering estimates derived from workflow analysis, not from a time-and-motion study of the actual manual baseline. No pre-implementation baseline measurement was conducted.

2. **Unvalidated volume parameter.** Annual report volume (A2 = 1,200) is estimated rather than observed. Section 11.3 establishes a break-even volume of 883 reports per annum with the maintenance-reduction benefit excluded; the estimate is therefore not extravagantly above the threshold, and validation is material.

3. **Unvalidated expenditure parameter.** The institutional repair and replacement expenditure (A4 = ₱1,200,000) underpinning B6 — the largest single benefit line — is an estimate from institutional scale, not from PMO financial records.

4. **Contested labor valuation.** The monetization of student and staff time at shadow-priced rates is a methodological choice on which reasonable analysts differ. Section 11.2 test S9 reports the result under the opposing assumption and finds the conclusion reversed. This is the study's most significant analytical vulnerability and is disclosed rather than minimized.

5. **Literature transferability.** The CMMS ROI benchmarks in §4.1 derive predominantly from industrial and vendor-authored sources whose reference populations differ materially from an educational institution. The 5% maintenance-reduction rate applied in §8.6 attempts to compensate, but the compensation factor is itself a judgment.

6. **No attribution controls.** The analysis attributes to the system the entirety of any improvement in the measured outcomes. In practice, concurrent changes in PMO staffing, procedures, or institutional attention would confound attribution in any post-implementation evaluation.

7. **Static benefit assumption.** Benefits are modeled as constant across Years 1–5. In practice a learning curve would suppress Year-1 benefits and a maturity effect might raise later-year benefits; the net effect on NPV is indeterminate but likely modest.

8. **Single-institution scope.** Findings are specific to Batangas Eastern Colleges and are not generalizable without re-parameterization.

---

## 16. CONCLUSIONS

Within the stated assumptions and limitations, the study concludes as follows.

**16.1** The BEC Web-Based Defective Equipment Reporting Management System is **economically justified** under base-case assumptions. It returns a five-year Net Present Value of **₱323,830** at the NEDA-prescribed 10% social discount rate, a Benefit–Cost Ratio of **2.06**, a five-year Return on Investment of **106.2%**, and an Internal Rate of Return of approximately **67%** — the latter exceeding the hurdle rate by 57 percentage points.

**16.2** The investment **recovers its full shadow-priced cost within 16.6 months** (18.9 months on a discounted basis). This falls within the 12–18 month payback range documented in the CMMS implementation literature, providing external plausibility support for the estimate.

**16.3** The system costs the institution an equivalent **₱80,455 per annum**, or **₱67.05 per defect report processed** and **₱16.09 per registered campus user per year**.

**16.4** The conclusion is **robust to substantial parameter error**. It survives a 51.5% overstatement of benefits, a 106% understatement of costs, a one-third reduction in report volume, discount rates from 6% to 15%, the complete removal of the largest single benefit line, and withdrawal of the AI provider's free tier (S11).

**16.5** The conclusion is, however, **conditional on accepting the monetization of institutional labor time**. Excluding all labor-time benefits reverses the finding (NPV −₱32,049, BCR 0.89). This dependency is the study's principal analytical caveat, and it is a question of benefit methodology that no reduction in operating cost addresses.

**16.6** Under the pessimistic scenario the project is **marginally justified but effectively at break-even** (NPV ₱373, IRR 10.1%) — a margin of roughly one-tenth of one percent of present-value cost, which is well inside the precision of the underlying estimates and should not be read as a positive return. The finding of consequence is that the downside is *bounded* at approximately zero, against an optimistic-case upside of ₱901,166. The **risk–return profile is strongly asymmetric in the institution's favor.**

**16.7** Against the commercial alternative, the in-house system's five-year total cost of ownership is **57.2% lower** than the least expensive comparable CMMS subscription and **82.6% lower** than a mid-tier platform. The **make decision is supported on cost grounds by a wide margin.**

**16.8** Ten intangible benefits are documented but not monetized, including accountability, identity verification, institutional data assets, and reduction of instructional disruption. **The quantified NPV should therefore be interpreted as a lower bound** on the system's institutional value.

---

## 17. RECOMMENDATIONS

**R1 — Validate the two critical parameters within the first operating quarter.** Obtain the actual annual equipment repair and replacement expenditure from PMO financial records (parameter A4), and record actual report volume from the system's own database (parameter A2). These two inputs jointly determine approximately 60% of quantified benefits. The instrument in Appendix C specifies the required data.

**R2 — Conduct a post-implementation time-and-motion study at the six-month mark.** Measure actual reporter and PMO transaction times against the Table 6.4 estimates. The system's `activity_log` and status-transition timestamps supply the "with system" measurements directly; the manual baseline must be reconstructed from PMO recollection and any surviving registers.

**R3 — Institutionalize the system as the sole official reporting channel.** Every quantified benefit stream scales with report volume. Parallel operation of informal channels is the single most effective way to destroy the return computed in this study (risk K1).

**R4 — Budget explicitly for maintenance continuity.** Recurring cost line R5 provides ₱6,800 per annum for 40 hours of maintenance labor, but presumes an available maintainer. Risk K2 (maintainer discontinuity following the capstone team's graduation) is assessed as high-likelihood and severe-impact. The institution should either designate an internal IT staff member, retain the developing team on a support arrangement, or budget for external maintenance at the prevailing ₱170/hour rate.

**R5 — Implement server-side pagination before the report backlog reaches 2,000 records.** Testing established that rendered page weight scales linearly with row count (1.3 MB at 510 reports; 11 MB at 5,010). At the base-case volume of 1,200 reports per annum this threshold is reached during Year 2. The remediation is a bounded engineering task and is properly charged to line R5.

**R6 — Retain the Supabase Pro subscription.** The free tier suspends projects after one week of inactivity and caps storage at 500 MB. Line R1 (₱18,306/yr) represents 22.0% of the equivalent annual cost and is not a defensible economy.

**R7 — Re-perform this analysis at the twelve-month mark using observed data.** This document is an ex-ante estimate. Its principal operational value lies in establishing a quantitative baseline; that value is realized only if an ex-post comparison is actually conducted. Appendix C provides the data-collection template.

**R8 — Report the base-case figures with the S9 caveat attached.** In presenting this analysis to institutional decision-makers or to a thesis panel, the finding that excluding labor-time benefits reverses the conclusion (§11.2) should be stated proactively. Disclosing the analysis's principal vulnerability is both methodologically proper and, in defense settings, strategically preferable to having it raised by an examiner.

---

## 18. REFERENCES

Accruent. (2026). *4 ways CMMS implementation increases ROI and cuts maintenance costs*. https://www.accruent.com/resources/blog-posts/4-ways-cmms-implementation-increases-roi-cuts-maintenance-costs

Google. (2026). *Gemini API pricing and rate limits* [Free-tier terms for `gemini-flash-lite-latest`]. https://ai.google.dev/gemini-api/docs/rate-limits

Chamberlain. (2026). *Minimum wage in the Philippines by region (2026)*. https://chamberlain.ph/resources/minimum-wage-philippines-2026/

ERI SalaryExpert. (2026). *Administrative officer salary in Philippines (2026)*. https://www.salaryexpert.com/salary/job/administrative-officer/philippines

eWorkOrders. (2026). *CMMS benefits: The quantified business case*. https://eworkorders.com/cmms-software/cmms-benefits/

Exchange-Rates.org. (2026). *US Dollar (USD) to Philippine Peso (PHP) exchange rate history for 2026* [August 2026 average: ₱61.02/US$1]. https://www.exchange-rates.org/exchange-rate-history/usd-php-2026

Fabrico. (2026). *CMMS software pricing guide 2026: How much does it actually cost?* https://www.fabrico.io/blog/cmms-software-pricing-guide-2026-how-much-does-it-actually-cost/

Indeed Philippines. (2026). *Junior developer salary in Philippines*. https://ph.indeed.com/career/junior-developer/salaries

ITQlick. (2026). *Limble CMMS pricing 2026: Hidden costs and total ROI revealed*. https://www.itqlick.com/limble-cmms/pricing

Jobstreet Philippines. (2026). *Web developer salary in Philippines (July 2026)*. https://ph.jobstreet.com/career-advice/role/web-developer/salary

Limble. (2026). *CMMS software cost: Updated pricing guide for 2026 evaluation*. https://limble.com/learn/cost

LLumin. (2026). *How to calculate the ROI of CMMS software (with examples)*. https://llumin.com/blog/how-to-calculate-the-roi-of-cmms-software-with-examples/

National Economic and Development Authority. (2016). *Revised social discount rate for the evaluation of government projects* [Investment Coordination Committee policy: social discount rate revised from 15% to 10%]. https://neda.gov.ph/investment-coordination-committee/

National Wages and Productivity Commission. (2026). *Region IV-A (CALABARZON) wage orders* [Wage Order No. IVA-22, effective 05 October 2025 and 01 April 2026]. Department of Labor and Employment. https://nwpc.dole.gov.ph/region-iva/

Oxmaint. (2026). *CMMS ROI calculator: Real numbers and methodology*. https://oxmaint.com/article/cmms-roi-calculator-methodology

Philippine Information Agency. (2025). *Wage board grants ₱25 to ₱100 daily wage hike in CALABARZON*. https://pia.gov.ph/news/wage-board-grants-p25-to-p100-daily-wage-hike-in-calabarzon/

Supabase. (2026). *Supabase pricing* [Pro plan: US$25/month/project]. https://supabase.com/pricing

UI Bakery. (2026). *Supabase pricing in 2026: Plans, free tier limits and full breakdown*. https://uibakery.io/blog/supabase-pricing

WageIndicator Foundation. (2026). *Minimum wage revised in IV-A — CALABARZON, Philippines, from 01 April 2026*. https://wageindicator.org/work/minimum-wage/updates/2026/minimum-wage-revised-in-iv-a-calabarzon-philippines-from-01-april-2026-april-02-2026/

---

## 19. APPENDICES

### Appendix A — Formula Reference

| Indicator | Formula |
|---|---|
| Present value of a single amount | $PV = FV(1+r)^{-t}$ |
| Present value annuity factor | $A_{n,r} = \dfrac{1-(1+r)^{-n}}{r}$; for *n*=5, *r*=10%: **3.790787** |
| Net Present Value | $NPV = \sum_{t=0}^{n}(B_t - C_t)(1+r)^{-t}$ |
| Benefit–Cost Ratio | $BCR = PV(B) \div PV(C)$ |
| Return on Investment | $ROI = NPV \div PV(C) \times 100\%$ |
| Internal Rate of Return | *r\** such that $NPV(r^*) = 0$ |
| Simple payback period | $PP = I_0 \div \bar{F}$, where $\bar{F}$ = annual net cash flow |
| Discounted payback | $PP_d = y + \dfrac{\lvert CumPV_y \rvert}{PV_{y+1}}$ |
| Equivalent Annual Cost | $EAC = PV(C) \div A_{n,r}$ |
| Labor benefit (generic) | $B = V \times \dfrac{m}{60} \times w$, where *V* = volume, *m* = minutes saved, *w* = hourly rate |

### Appendix B — Consolidated Parameter Register

| Code | Parameter | Base value | Source | Validation status |
|---|---|---:|---|---|
| A1 | Campus population | 5,000 | Institutional | Estimated |
| A2 | Annual report volume | 1,200 | Estimated | **Requires validation** |
| A3 | PMO complement | 5 persons | Institutional | Estimated |
| A4 | Annual repair/replacement expenditure | ₱1,200,000 | Estimated | **Requires validation** |
| A5 | Evaluation horizon | 5 years | Convention | Fixed |
| L1 | Reporter hourly rate | ₱100 | NWPC shadow price | Judgment |
| L2 | PMO staff hourly rate | ₱115 | ERI SalaryExpert | Benchmarked |
| L3 | Technician hourly rate | ₱90 | NWPC + benefits | Benchmarked |
| L4 | Developer hourly rate | ₱170 | Indeed / Jobstreet | Benchmarked |
| T1 | Supabase Pro | US$300/yr | Vendor published | Verified |
| T2 | VPS hosting | US$72/yr | Market rate | Estimated |
| T3 | Domain | ₱900/yr | Market rate | Estimated |
| T4–T5 | Claude API (Haiku 4.5) | US$45/yr | Vendor pricing × usage estimate | Estimated |
| T6 | USD–PHP rate | ₱61.02 | Exchange-Rates.org | Verified |
| P1 | Reporter minutes saved/report | 20 | Engineering estimate | **Requires validation** |
| P2 | PMO minutes saved/report | 15 | Engineering estimate | **Requires validation** |
| P3 | Report-compilation hours saved/month | 7 | Engineering estimate | Estimated |
| P4 | Repeat-visit avoidance rate | 12% | Engineering estimate | Estimated |
| P5 | Maintenance expenditure reduction | 5% | Literature (haircut applied) | **Requires validation** |
| D1 | Discount rate | 10% | NEDA-ICC | Verified |

### Appendix C — Post-Implementation Data Collection Instrument

To be completed at the six- and twelve-month marks and substituted into §6 to produce the ex-post analysis contemplated by Recommendation R7.

**C.1 — System-derived data** (obtainable directly by query; no manual collection required)

| # | Metric | Source | Value observed |
|---|---|---|---|
| 1 | Total reports submitted in period | `defect_reports` row count by date range | ________ |
| 2 | Reports by status at period end | `defect_reports` grouped by status | ________ |
| 3 | Mean time from submission to PMO receipt | `received_by_pmo_at` − submission timestamp | ________ |
| 4 | Mean time to resolution (MTTR) | Closure timestamp − submission timestamp | ________ |
| 5 | Distinct reporters served | `defect_reports` distinct `reporter_email` | ________ |
| 6 | Follow-up ("bump") events recorded | `follow_up_count` aggregate | ________ |
| 7 | Reporter satisfaction confirmations | Satisfaction field aggregate | ________ |
| 8 | Notification emails dispatched | Mail log / `notifications` count | ________ |

**C.2 — Institutionally-sourced data** (requires PMO and Finance cooperation)

| # | Metric | Source | Value observed |
|---|---|---|---|
| 9 | Annual equipment repair expenditure | PMO/Finance disbursement records | ________ |
| 10 | Annual equipment replacement expenditure | Asset register additions | ________ |
| 11 | Actual PMO staff complement and salary grades | HR records | ________ |
| 12 | Actual technician complement and rates | HR records | ________ |
| 13 | Prior-year printing and forms expenditure | Purchase records | ________ |

**C.3 — Primary observation** (requires time-and-motion study)

| # | Metric | Method | Value observed |
|---|---|---|---|
| 14 | Observed reporter submission time | Direct timing, n ≥ 30 | ________ |
| 15 | Observed PMO per-report handling time | Direct timing, n ≥ 30 | ________ |
| 16 | Observed monthly report compilation time | PMO self-report / observation | ________ |
| 17 | Repeat site visits as % of jobs | Technician log review | ________ |
| 18 | Status inquiries received per month (non-system) | PMO tally sheet, 1 month | ________ |

### Appendix D — Reconciliation of Rounding

All monetary figures are computed to the peso and presented without decimals. The present-value annuity factor is carried at six decimal places (3.790787) throughout. Discrepancies of ±₱2 between independently computed figures and their component sums are attributable to rounding and are not material to any conclusion.

---

*Prepared as an economic evaluation component of the capstone study "BEC Web-Based Defective Equipment Reporting Management System," Batangas Eastern Colleges — Property Management Office. All cost and benefit estimates are ex-ante projections subject to the limitations stated in §15.*
