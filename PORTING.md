# Faturly

Language-agnostic build specification for reimplementing Faturly. **Self-contained:** this
document plus one machine artifact — `saftpt1.04_01.xsd`, the official SAF-T (PT) 1.04_01
schema (XSD 1.1; libxml2/xmllint cannot compile it) — is the complete build reference.
Where this document and the XSD disagree, the XSD wins. Legal citations (Portaria, CIVA,
Despacho, FAQ numbers) identify the governing law and are kept for AT correspondence; no
other external document is required.

Every rule below carries a tier:

- **[A] Compliance contract** — law or inspector doctrine (AT certification process 3348).
  Must hold in any language, byte-exact or digit-exact where stated. Failure = certification failure.
- **[B] Architectural guarantee** — exists to make an [A] rule structurally impossible to
  violate. Reproduce the guarantee; the mechanism may be idiomatic to the target language.
- **[C] Implementation choice** of the Go reference codebase. Free to change.

Untiered statements in Part II are [A]; in Parts III–IV they are [B].

---

# Part I — System

## 1. Product and certification context

Faturly is AT-certifiable Portuguese invoicing software (Portaria 363/2010 certification,
SAF-T (PT) export, ATCUD, QR codes, signed-document print regime). The certification
inspector tests observable behavior only: byte-exact signatures, byte-exact QR payloads,
digit-exact rounding, a SAF-T file valid against the official XSD, and live probes of race
conditions and correction interlocks in a videoconference session. The dominant failure
mode is **byte divergence, not logic error**: a signature over `"105.34"` vs `"105.340"`
fails with all business logic correct. The architecture exists to make that class of
failure structurally impossible.

Two planes, composed strictly one-way:

- **Fiscal plane** (the certified core): documents, ledgers, signing, QR, print, SAF-T,
  and their stores. Single-emitter semantics.
- **Control plane**: tenants, users, tokens, API keys, idempotency — the hosted
  multi-tenant layer. It composes *above* the fiscal plane through exactly one seam
  (tenant config + software config → fiscal emitter config), never the reverse.

## 2. Certification process facts [A]

- One **Modelo 24** (certificate request) per product, submitted with the RSA **public**
  key: a `.txt` that is a renamed PEM, base-64 **with** `-----BEGIN/END PUBLIC KEY-----`
  markers (1024-bit key → 216 base-64 chars). One key pair per product; key change only
  via a new Modelo 24 + public-key upload; key version = sequential integer.
- Certification identity: `ProductID` = `SoftwareName/ProducerLegalName` verbatim as
  declared (exactly one `/`), `ProductVersion` verbatim (e.g. `1.0.0`). The certified
  version is exactly the Modelo 24 version; changing it mid-process = new Modelo 24. The
  certificate number and the private key are two halves of one AT-registered identity and
  must be deployed together. A rewrite ships as the same product only under the same
  Modelo 24 identity and key.
- Process shape: AT returns an anomaly report against a submitted test dataset
  (PDFs + SAF-T); no response deadline — the process suspends. Resubmission = redo **all**
  test points, PDFs + SAF-T, directly to the inspector's email. When files are correct →
  videoconference conformity testing of **runtime blocking behavior**, not just files.
- Commercializing invoicing software requires producer CAE 46501 or 47401. Every
  (re)submission answers two AT questions: commercialization vs in-house use, and which DB
  safeguard systems exist (answer frame in §22).
- Certified behavior applies to **every user** of the program, obligated or not; no dual
  builds; compliance features can never be user-configurable off. After certification the
  producer must keep all requirements in every release (revocation risk, art. 10.º);
  conformity tests can occur at any time, and AT may demand a program copy + documentation
  including the **data dictionary**.
- Who must use certified software (DL 28/2019 art. 4.º): PT-established taxpayers with
  previous-year turnover > €50 000 (annualized for partial years), or who use invoicing
  software at all (adopting Faturly triggers the obligation by itself), or with organized
  accounting. Other electronic means (cash registers, terminals) may emit FS only. Exempt
  from certified means (pre-printed docs or non-DB electronic means only): transport
  tickets, parking/toll receipts, event/museum entries, itinerant amusement, vending
  machines. Program inoperability ⇒ emit from authorized typography and later **recover
  into the program** (§21).

## 3. Feature inventory

**Document kinds** (closed taxonomy; a kind outside this table must be unemittable — dead
kinds VD/TV/TD/AA/DA are valid only for data ≤ 2012-12-31, DC ≤ 2017-06-30; insurer types
RP/RE/CS/LD/RA are schema-valid but out of scope):

| Family (SAF-T table) | Kinds | Signed | Line orientation |
|---|---|---|---|
| Sales (4.1) | FT fatura · FS fatura simplificada · FR fatura-recibo · ND nota de débito · NC nota de crédito | yes | Credit — **including ND**; only NC is Debit |
| Movement (4.2) | GR guia de remessa · GT guia de transporte · GA guia de movimentação de ativos fixos próprios · GC guia de consignação · GD guia de devolução | yes | Credit |
| Working (4.3) | CM consulta de mesa · CC crédito de consignação · FC fatura de consignação · FO folha de obra · NE nota de encomenda · OU outros · OR orçamento · PF pró-forma | yes (incl. orçamentos) | Credit |
| Payments (4.4) | RC (recibo, incl. IVA de caixa) · RG (outros recibos) | **structurally unsigned** | — |

**Flows:** draft → emit (all families); preview (full pipeline, dry-run abort, nothing
persisted); annul; print to PDF (vias regime, audited re-originals; the certification
process submits PDFs); manual typography recovery
(**legally mandatory feature**); duplicate recovery after backup loss; integration of
documents from other certified systems; SAF-T export; hash-chain verification; series
lifecycle (open / roll fiscal year / inhibit / close) with purposes normal · training ·
integration · manual-recovery · duplicate-recovery; masterdata (customers, suppliers,
products) with legal freezes and snapshots; AT webservice communication (series, invoices,
transport docs).

**Surfaces:** a single-tenant CLI (selfcheck + scripted demo); a multi-tenant HTTP API
(serve / migrate / verify / sweep); three store implementations (in-memory reference,
SQLite single-tenant, Postgres multi-tenant with row-level security).

---

# Part II — The compliance contract [A]

## 4. The twelve non-negotiables

1. **Signatures are never regenerated** (FAQ24/27: "NUNCA!"). No re-sign path may exist.
   Correction = annul + new appended document.
2. **RSA-SHA1 · PKCS#1 v1.5 · 1024-bit, exactly.** SHA-256 or PSS ⇒ «assinaturas
   inválidas». Hard-code digest, padding, key size; reject non-1024-bit keys at construction.
3. **One rendering of GrossTotal.** The 2-decimal string is computed once at emission,
   persisted, and reused byte-identically by signature, QR and SAF-T.
4. **ROUND-then-multiply.** `UnitPrice = ROUND(H, N)` is the one line-level rounding;
   `CreditAmount = UnitPrice × Qty` exact, never re-rounded. Discount composition is
   multiplicative. A naive `round(x, 2)` in the chain diverges from the fixtures.
5. **QR payload generated and persisted at emission, immutable.** Print reads the stored
   payload verbatim; field E carries the creation status forever (annulled doc still `E:N`).
6. **SAF-T is Windows-1252, not UTF-8** — the most common file-level rejection cause.
   Representability is checked at draft build time.
7. **Line descriptions ≡ catalog descriptions exactly.** No free-text line path may exist
   over catalog products; a changed description is a new product.
8. **All sales-side documents including ND carry CreditAmount; only NC uses DebitAmount.**
   Orientation derives from the kind table, never caller choice.
9. **Exemption M-codes are versioned reference data** with validity windows, each code
   paired with its exact printed mention. Never compiled-in constants.
10. **Manual recovery tolerates out-of-order dates; duplicate recovery demands
    date-sequential entries.** Deliberately opposite. Do not harmonize.
11. **Fail closed on ambiguity.** Undeclared FS regime → €100 cap. Unknown tax rate →
    error, never a guess. Ambiguous origin resolution → whole transaction fails.
    Uncertified integration → blocked.
12. **Nothing is ever deleted.** Series are inhibited; documents are annulled; both are
    appended transitions. No delete operation exists on any fiscal record.

## 5. Software identity and SAF-T Header

Cross-checked by the inspector against the Modelo 24 and the AT registry:

- `TaxAccountingBasis` = **"F"** (invoicing-only). Other codes: C accounting, E
  third-party billing, I integrated, P partial, R receipts-only, S self-billing, T
  transport-only.
- `ProductCompanyTaxID` = the **producer's** NIF (the one that submitted the Modelo 24),
  regardless of the emitter's NIF. `ProductID` and `ProductVersion` verbatim per §2.
  `SoftwareCertificateNumber` = attributed number, `"0"` while none.
- Emitter fields: `TaxRegistrationNumber` = PT NIF, no prefix; `CompanyID` = conservatória
  + registration number space-separated (NIF if none); `Country` = "PT" (fixed by the
  XSD for the company address); `CurrencyCode` = "EUR"; `TaxEntity` = establishment or
  "Global" for invoicing; `FiscalYear` per CIRC rules for non-calendar periods.
- Formats everywhere: dates `AAAA-MM-DD`; timestamps `AAAA-MM-DDThh:mm:ss` — **no
  timezone, no milliseconds**.
- Configuration split (load-bearing): the **software half** (producer name/address,
  ProductCompanyTaxID, ProductID, ProductVersion, CertificateNo) is a deployment input
  travelling with the signing key, identical for every tenant; the **tenant half** (NIF,
  company name, CompanyID, company address) is per-tenant data. A tenant able to set its
  own certificate number would emit QR field R, the printed certificate mention and the
  SAF-T Header under an identity AT never certified for the signing key — undetectably.
  Certificate-number parsing fails closed: empty = 0 (uncertified); non-numeric = a
  configuration error, never silently 0.

## 6. States, status changes, sources

- `InvoiceStatus`: N normal · S self-billing · A annulled · R summary of other-application
  docs · F billed. `MovementStatus`: N · T (por conta de terceiros) · A · F (invoiced,
  even partially, when a corresponding FT/FS exists) · R. `WorkStatus`: N · A · F.
  `PaymentStatus`: N · A. Illegal (family, status) pairs must be unrepresentable.
- Every status change records the date-time (to the second), a Reason, and the SourceID
  (user) that made it.
- `SourceBilling` / `SourcePayment`: **P** produced here · **I** integrated from another
  application · **M** manual/duplicate recovery.
- Documents client-presentable that are not faturas/rectifying docs must evidently state
  their nature and print **«Este documento não serve de fatura»** (all 4.2/4.3/4.4 docs).

## 7. Series and numbering

- All types emitted **chronologically per series**, numbered **progressively and
  continuously**; a series lives ≥ 1 fiscal year (multi-year allowed if used until the end
  of the running fiscal year).
- The sequence is **exclusively numeric** — no year/terminal metadata inside the number
  (that belongs in the series identifier), nothing violating the XSD or readable as XML
  operators.
- **Series identifier** (FAQ 41-4696): max 35 chars; every char identically representable
  in UTF-8 and Windows-1252; allowed `[A-Z]` `[a-z]` (no accents, no ç/Ç), `[0-9]`, and
  `. _ -` only **between** other characters — never at start/end, never consecutive;
  identifiers starting with **"AT" are reserved and must be rejected**. Validation regex:
  `^(?!AT)(?![._-])(?!.*[._-]{2})[A-Za-z0-9._-]{1,35}(?<![._-])$`
  Enforce at series creation **and** on the series inputs of both recovery flows.
- Series unique per taxpayer + document type, even across programs. Unique doc ID:
  `‹internal type code› ‹space› ‹series›/‹sequence›`; internal type codes never reused
  across document types.
- Numbering must be consistent with emission date-time evolution (the §20 alerts enforce
  it). Discontinued series are **inhibited, never deleted**. Annulled documents remain in
  SAF-T, flagged, preserving sequence verifiability.

## 8. Immutability and master-data freezes

- Once signed, nothing fiscally relevant changes: CIVA 36.º/40.º elements (NIFs,
  quantities, values, rates, tax amounts), transport elements, signature elements. No
  "change with evidence" escape. No document in preparation may be printed before
  finalization + signature.
- Customer record: **NIF frozen** once documents exist (only filling a missing NIF or
  replacing the generic 999999990 is allowed); **name frozen while NIF absent** (lifts
  once a NIF is recorded).
- Product record: **ProductDescription frozen** once used in documents.
- **SourceID (user codes) never reused** after fiscally relevant movements — one
  never-reuse namespace shared by human users and API keys.

## 9. Rectification and cross-document interlocks

Tested live in the conformity videoconference; the application must **block**, not warn:

- No NC over documents already annulled or fully rectified.
- Annulling a document with any (even partial) rectifier requires **first annulling the
  rectifier**.
- Partial NCs are capped against the rectified invoice's remaining **quantities AND
  values** per product — both checks (a discounted NC line can satisfy the value cap while
  over-crediting units).
- **ND corrects only VALUES of products already on the referenced invoice — never
  quantities, never absent products.** Reference design: the ND input cannot even express
  a quantity change — it carries only `{target line, positive unit-value delta}` (a
  reduction is an NC); lines materialize from the locked target inside the emission
  transaction.
- No returns inside sale documents; no transmissions inside rectifying documents.
- Rectifiers identify their targets in `References` (recovered manual originals cited by
  their **manual** series/number); documents originating from other documents (fatura from
  orçamento/guia) cite them in `OrderReferences`.
- Billing an annulled guia blocks. Only FT/FS/FR mark a guia/work document `F` (billed) —
  and that transition commits **atomically with** the invoice emission.
- NC VAT regularization (CIVA art. 78.º): the supplier may only regularize VAT in its
  favor after holding proof the acquirer took knowledge of the rectification (or was
  reimbursed) — a records/workflow obligation, not a document-format rule (verbatim
  article unverified, §42).

## 10. Type-specific content rules

- **FR** is settled at issuance ⇒ mandatory Payment block (mechanism + amount + date,
  repeatable for mixed payments) **and** the payment info printed.
- Printed due date («Data de Vencimento») ⇒ export `DocumentTotals→Settlement→PaymentTerms`
  with the due date as value.
- **GT valorizada:** `MovementStartTime` may not precede `SystemEntryDate`. Printed
  vehicle plate ⇒ export `ShipFrom→DeliveryID`.
- **GT não valorizada:** if products have DB prices/VAT rates → export as valued (the
  total feeds the hash). If not → `UnitPrice 0.00`, **no Tax element** on lines, print
  shows no prices/rates/totals, QR carries zeros + `I1:0`, GrossTotal signed as `0.00`.
- **Print⇄export symmetry:** every optional field exported must be visible on the print
  (e.g. MovementEndTime), and every fiscally relevant printed datum must be exported (due
  date, plate, FR payment).
- Emission deadline realism: invoices at latest the **5th working day** after tax is due
  (CIVA 36.º n.º 1); surface a breach as an alert; large InvoiceDate↔SystemEntryDate gaps
  in test data are flagged by the inspector.
- `TaxPointDate` = goods-dispatch/service date (oldest associated guia if several); the
  app records it per line.
- Consumer final: generic customer «Consumidor final», NIF **999999990**, addresses
  «Desconhecido».
- **FS eligibility caps** (CIVA art. 40.º) — the application must block above them:
  retail/ambulant sales to non-taxable persons ≤ **€1 000** per invoice; anything else ≤
  **€100**; art. 53.º-regime taxpayers eligible. Undeclared regime fails closed to the
  €100 branch. Above cap → full FT.

## 11. Money: precision, discounts, totals

**Decimal arithmetic requirements [A+B]:**

- No binary floating point on any money path — including parsing (parse money from
  strings) and rendering. Reference representation: exact decimal
  `coefficient × 10^-scale` on arbitrary-precision integers.
- Internal precision `N` is a parameter; AT reference value **N = 5**.
- Two distinct roundings: `ROUND(x, n)` = single-step half-away-from-zero (the chain
  rounding below); **cascade** (FAQ41) = drop one decimal place at a time, rounding
  half-away-from-zero at each step, until 2 decimals remain — used on the GrossTotal
  formatting path: `10.1446 → 10.145 → 10.15` (single-step half-up gives `10.14` — wrong).
- Rendering never rounds; a rendering that would truncate non-zero digits is a program
  bug (crash), not an input condition.
- 2-decimal lexical rendering: `1000 → 1000.00`; `0.1 → 0.10`; `.1 → .10` (**no** left
  zero added). Recovery flows reproduce the operator's typed totals verbatim through this
  lexical form, human error included.

**The formula chain** (extracted cell-by-cell from the AT workbook; per line with
tax-exclusive unit price PU, line-discount rate d_line, global-discount rate d_global):

```
E  = PU × d_line                        (unrounded)
F  = PU − E                             (unrounded)
G  = F × d_global                       global applies ON the line-discounted price:
                                        composition is MULTIPLICATIVE
H  = F − G                              (unrounded)
UnitPrice        = ROUND(H, N)                       ← the ONE line-level rounding
CreditAmount     = UnitPrice × Quantity              ← exact, NOT re-rounded
SettlementAmount = ROUND(Quantity × (E + G), N)      ← total line discount
LineVAT          = CreditAmount × rate               (unrounded)
TaxPayable = ROUND(Σ LineVAT, 2)
NetTotal   = ROUND(Σ CreditAmount, 2)
GrossTotal = ROUND(TaxPayable + NetTotal, 2)         ← ALWAYS 2 decimals
```

The 2-decimal GrossTotal is simultaneously what gets signed, QR-encoded and exported.
The worked example (§37, fixture 1) must reproduce digit-exact including intermediates.
The workbook covers tax-exclusive pricing only; tax-inclusive back-computation has no AT
fixture — derive symmetrically and flag for the AT videoconference if used.

**Discount model:**

- Global (header) discounts are **redistributed into the lines** — UnitPrice is net of
  line and header discounts; line SettlementAmount is the total discount attributable to
  that line. Never a document-level discount line.
- `DocumentTotals→Settlement` is exclusively for **future** discounts (acordos) and the
  due-date carrier — never immediate discounts.
- Discounts constrained 0–100 %.
- Printed documents contain **no negative values**; exceptions only for annulment of
  lines inside the same document or continuous-service estimate adjustments, never
  exceeding the positive of the same item; beyond that ⇒ credit note.
- Franchises, guarantees, withholdings: dedicated fields after GrossTotal, no influence on
  totals, never modeled as products. Withholding (type IRS/IRC/IS + description + amount)
  never reduces GrossTotal; QR field P carries it.
- Foreign currency: Currency block (ISO 4217, original-currency GrossTotal, exchange rate
  to EUR) for non-EUR only; rate of the **emission date**; the EUR countervalue is what
  gets signed and is shown on the print (total + VAT summary).

## 12. Tax representation

- TaxTable (2.5): TaxType IVA / IS / **NS**; TaxCountryRegion PT / PT-AC / PT-MA;
  TaxCode RED / INT / NOR / ISE / OUT (IVA), verba / ISE (IS), NS, NA (receipts without
  itemized tax); TaxPercentage 0 for exempt/NS; TaxAmount for fixed IS verbas
  (TaxPercentage ⊕ TaxAmount is an XSD choice).
- Whenever rate/amount = 0 (including NS): **both** TaxExemptionReason (the legal precept,
  min 6 chars) **and** TaxExemptionCode (M-code, §14) are mandatory, and the user must be
  **forced to state the reason**.
- Every tax used in a line must exist in the TaxTable (referential integrity).
- Rate → bucket classification comes from the versioned rate table (§13) at the
  document's date; an unknown rate is an error, never a guess.
- QR mapping: exempt (ISE) bases → I2 (which also absorbs IS-taxable operation bases);
  **NS totals → L, never I2**; IS total → M.
- One classification source: the same persisted per-document tax summary feeds both the
  QR assembly and the SAF-T projection, so the two surfaces cannot disagree.

## 13. VAT rate table (reference data)

Model as loaded data with validity windows, not constants. Loaders reject
overlapping/ambiguous windows; historical documents resolve against historical windows —
**add a new window, never edit an old one**. Current table (from 2024-01-01):

| Space | RED | INT | NOR | ISE | NS |
|---|---|---|---|---|---|
| PT (Continente) | 6 % | 13 % | 23 % | 0 | 0 |
| PT-MA (Madeira) | 4 % | 12 % | 22 % | 0 | 0 |
| PT-AC (Açores) | 4 % | 9 % | 16 % | 0 | 0 |

## 14. Exemption M-codes (reference data)

Official table v4.0 (2026-06-18). The «Menção» is printed on the invoice **verbatim**.
XSD pattern `(M[0-9]{2})+`. M03 and M08 are retired. Version history (drives validity
windows): v1.0 2022-07-01 → v2.0 2023-04-14 (+M26, M34) → v3.0 2025-08-14 (+M44–M46) →
v4.0 2026-06-18 (+M35, for invoices emitted after 2026-07-01). The table changes with
legislation — ship it as maintained data.

| Code | Menção (printed) | Legal basis |
|---|---|---|
| M01 | Artigo 16.º n.º 6 do CIVA | CIVA art. 16.º n.º 6 a)–d) |
| M02 | Artigo 6.º do Decreto-Lei n.º 198/90, de 19 de junho | DL 198/90 art. 6.º (>€1000 sales to exporters) |
| M04 | Isento artigo 13.º do CIVA | CIVA art. 13.º |
| M05 | Isento artigo 14.º do CIVA | CIVA art. 14.º (exports) |
| M06 | Isento artigo 15.º do CIVA | CIVA art. 15.º |
| M07 | Isento artigo 9.º do CIVA | CIVA art. 9.º (internal exemptions) |
| M09 | IVA – não confere direito a dedução | CIVA art. 62.º b) |
| M10 | IVA – regime de isenção | CIVA art. 53.º n.º 1 |
| M11 | Regime particular do tabaco | DL 346/85 |
| M12 | Regime da margem de lucro – Agências de viagens | DL 221/85 |
| M13 | Regime da margem de lucro – Bens em segunda mão | DL 199/96 |
| M14 | Regime da margem de lucro – Objetos de arte | DL 199/96 |
| M15 | Regime da margem de lucro – Objetos de coleção e antiguidades | DL 199/96 |
| M16 | Isento artigo 14.º do RITI | RITI art. 14.º (intra-EU) |
| M19 | Outras isenções | Temporary exemptions in own diploma |
| M20 | IVA – regime forfetário | CIVA art. 59.º-D n.º 2 |
| M21 | IVA – não confere direito à dedução (ou expressão similar) | CIVA art. 72.º n.º 4 |
| M25 | Mercadorias à consignação | CIVA art. 38.º n.º 1 a) |
| M26 | Isenção de IVA com direito à dedução no cabaz alimentar | Lei 17/2023 |
| M30 | IVA - autoliquidação | CIVA art. 2.º n.º 1 i) |
| M31 | IVA - autoliquidação | CIVA art. 2.º n.º 1 j); for empreitadas under DL 97/2026 use M35 |
| M32 | IVA - autoliquidação | CIVA art. 2.º n.º 1 l) |
| M33 | IVA - autoliquidação | CIVA art. 2.º n.º 1 m) |
| M34 | IVA - autoliquidação | CIVA art. 2.º n.º 1 n) |
| M35 | IVA - autoliquidação | CIVA art. 2.º n.º 1 j) — Verba 2.42 Lista I, DL 97/2026; invoices after 2026-07-01 |
| M40 | IVA - autoliquidação | CIVA art. 6.º n.º 6 a), a contrário |
| M41 | IVA - autoliquidação | RITI art. 8.º n.º 3 |
| M42 | IVA - autoliquidação | DL 21/2007 (real estate) |
| M43 | IVA - autoliquidação | DL 362/99 (investment gold) |
| M44 | IVA – Regras específicas - artigo 6.º | CIVA art. 6.º n.º 7 ss. |
| M45 | IVA – regime transfronteiriço de isenção | CIVA art. 58.º-A |
| M46 | IVA – e-TaxFree | DL 19/2017 |
| M99 | Não sujeito ou não tributado | Other non-liquidation cases — the default; the natural code for NS lines |

A frozen document's persisted mention must survive later table versions unchanged.

## 15. Signature engine

**Cipher:** RSASSA-PKCS1-v1_5 · SHA-1 · 1024-bit key · message bytes UTF-8 · output
base64, **exactly 172 characters**, single line, no separators. X.509 self-signed
acceptable, no CA. Any other digest or padding ⇒ invalid signatures. Reference pipeline
for cross-checking:
`openssl dgst -sha1 -sign priv.pem -out r.sha1 msg.txt && openssl enc -base64 -in r.sha1 -out r.b64 -A`;
verify with `openssl dgst -sha1 -verify pub.pem -signature sig.bin msg.txt`.

**Message** — fields joined with `;` only, no quotes, no trailing newline:

| Family | Message |
|---|---|
| Sales (4.1) | `InvoiceDate;SystemEntryDate;InvoiceNo;GrossTotal;PrevHash` |
| Movement (4.2) | `MovementDate;SystemEntryDate;DocumentNumber;GrossTotal;PrevHash` |
| Working (4.3) | `WorkDate;SystemEntryDate;DocumentNumber;GrossTotal;PrevHash` |

- First document of a chain: PrevHash empty ⇒ the message ends with the trailing `;`.
- **Signing moment** = sequential-number assignment = DB save = document conclusion
  (FAQ23). SystemEntryDate is captured at the moment of signing — one clock capture per
  emission, reused pipeline-wide.
- GrossTotal in the message: always exactly two decimals, `.` separator, cascade-rounded
  (§11), lexical rules (§11). Foreign currency: sign the **EUR countervalue**. Unvalued
  4.2/4.3 docs: GrossTotal = DB value; absent ⇒ `0.00`, signed as such. SAF-T exports the
  **same** 2-decimal value that was signed.

**Chains:**

- One chain per **(document type, series)** pair, even when types share a numbering
  sequence (FT 1,2,4 / ND 3,5 → chains FT:1→2→4, ND:3→5). SAF-T export order within
  type+series: ascending number.
- Multi-year series: at fiscal-year start the first document **may** chain from the last
  of the same series/type of the previous year (alternative to restarting).
- Receipts (RC/RG) are **structurally unsigned**: no hash field exists on the type; the
  SAF-T Payment type has no Hash element; a receipt emission passes the chain head's last
  hash through unchanged.
- Hash + HashControl stored in the DB — which **cannot be encrypted** and persists for the
  archive period — associated to the full original record; exported exactly as stored.
- Signer failure aborts the emission atomically: no record, no head advance, **no
  numbering gap**.

**HashControl grammars** (XSD caps at 70 chars — enforce **after** signing, since the real
key version can widen it past any pre-check):

| Source | Grammar | Example |
|---|---|---|
| Own document | key version integer | `1` |
| Integrated | `‹origin certificate›.‹key version›` | `9999.1` |
| Manual recovery | `‹keyver›-‹TYPE›M ‹manual series›/‹n.º›` | `1-FTM abc/1` |
| Duplicate recovery | `‹keyver›-‹TYPE›D ‹internal code› ‹series›/‹n.º›` | `1-FTD FA abc/1` |

The XSD pattern (verbatim):
`[0-9]+|[0-9]+[\.][0-9]+|[0-9]+-[A-Z]{2}(M )([^/^ ]+[/][0-9]+)|[0-9]+-[A-Z]{2}(D )([^ ]+ [^/^ ]+[/][0-9]+)`
It **rejects** the literal «Não certificado» prescribed in prose for uncertified origins —
therefore uncertified-origin integration is blocked entirely (fail closed; open gap, §42).

## 16. ATCUD and series communication

- Before use, per **document type × processing means**, communicate to AT: series
  identifier; document type (including receipt types); starting sequential number;
  expected start date. Channels: Portal das Finanças (manual) or the seriesWS webservice
  (contract still an open gap; AT provides a producer test environment). One validation
  code per (series, type, means) — an FS series and an NC series get different codes.
- Validation code: **≥ 8 characters**, no defined maximum; uppercase consonants + digits,
  **excluding "0" and "1"**; validate manually-typed codes.
- **ATCUD = `‹ValidationCode›-‹SequentialNumber›`** — the sequence immediately after the
  `/` of the unique doc ID. Example: doc `ABC BNH/4561`, code `TES123TE` → `TES123TE-4561`.
- Printed as **`ATCUD:CODE-NUM`** on **every page**, on the QR page immediately above the
  QR, genuinely legible. The `ATCUD:` prefix is display-only — QR field H and SAF-T carry
  `CODE-NUM` only.
- **Emission without a series validation code is blocked.** A document generated with a
  wrong ATCUD cannot be fixed — annul + reissue (FAQ 4326).
- Scope: every fiscally relevant document type, all processing means. QR, by contrast, is
  demanded only of certified-program output.

## 17. QR code

**Physical/encoding:** ECC **M** · Byte mode · 2 points/module · **version ≥ 9 floor even
for short payloads** · image ≥ 30×30 mm · quiet margin 0.25 cm · first or last page,
inside the printable body, **never against the bottom edge** (printer-margin clipping ⇒
invalid).

**Grammar:** fields `‹Code›:‹Value›`, no spaces, joined **in table order** with `*`.
Monetary values 2 decimals, `.` separator, no thousands separator. Date field F:
`YYYYMMDD`. Mandatory (+) fields always present; optional (++) fields **must be created
whenever the information exists** and **must not be emitted at all when absent** (an empty
`I3:` is malformed). ≥ 1 fiscal-space block always; **each rate gets its own discrete
field — never a colon-joined tuple**. Field S sub-elements are `;`-joined and must not
contain `*`.

| Code | Content | Max | Rule | Ob. |
|---|---|---|---|---|
| A | Issuer NIF | 9 | no prefix | + |
| B | Acquirer NIF | 30 | `999999990` consumidor final; foreign tax ID as registered | + |
| C | Acquirer country | 12 | = Customer Country (may be `Desconhecido`) | + |
| D | Document type | 2 | SAF-T typology | + |
| E | Document status | 1 | **status at creation — immutable** | + |
| F | Date | 8 | `YYYYMMDD` | + |
| G | Unique doc ID | 60 | e.g. `FT AB2019/0035` | + |
| H | ATCUD | 70 | `CODE-NUM`, no prefix | + |
| I1 | Fiscal space 1 | 5 | `PT`/`PT-AC`/`PT-MA`; **`0`** for unvalued 4.2/4.3/4.4 (then no I2–I8) | + |
| I2 | Exempt base | 16 | ISE bases + IS-taxable operation bases | ++ |
| I3 / I4 | reduced base / VAT | 16 | | ++ |
| I5 / I6 | intermediate base / VAT | 16 | | ++ |
| I7 / I8 | normal base / VAT | 16 | | ++ |
| J1–J8 / K1–K8 | fiscal spaces 2 / 3 | | same shape as I | ++ |
| L | NS (non-subject) total | 16 | NS goes here, **never** I2 | ++ |
| M | IS total | 16 | | ++ |
| N | Total taxes | 16 | = TaxPayable | + |
| O | Document total | 16 | = GrossTotal | + |
| P | Withholding | 16 | | ++ |
| Q | 4 hash chars | 4 | signature chars at 1-based positions 1, 11, 21, 31; receipts emit `Q:0` (unconfirmed convention, §42) | + |
| R | Certificate no. | 4 | from the software config | + |
| S | Free info | 65 | e.g. `TB;IBAN;amount`, `MB;ent;ref;val`, `NU;0.80`; immutable | ++ |

**Immutability (FAQ 4443):** the QR is never updated on reprint/exhibition, even after a
status change between finalization and print. Field E shows the **creation** status
(post-creation statuses like `A` can never appear). Architectural consequence: generate
and persist the payload at emission alongside Hash/HashControl; never rebuild from current
state.

The four inspector-corrected vectors (§37, fixture 3) must pass byte-exact.

## 18. SAF-T (PT) export

**File invariants:**

- Declaration `<?xml version="1.0" encoding="Windows-1252"?>`; namespace
  `urn:OECD:StandardAuditFile-Tax:PT_1.04_01`. Any rune not representable in CP-1252 is a
  hard error naming rune and byte offset — and is already blocked at draft build time.
- Required tables for an invoicing ("F") file: 1 Header; 2.2 Customer; 2.3 Supplier (when
  it must exist); 2.4 Product; 2.5 TaxTable; 4.1 SalesInvoices; 4.2 / 4.3 (when they must
  exist); 4.4 Payments (when it must exist). Tables 2.1/3 are accounting-only — never in
  an "F" file.
- Valid against the XSD **and** the content rules. Generated per annual tax period (full
  or partial, period start → end or generation date). Header period fields:
  AuditFileVersion `1.04_01`; StartDate/EndDate = the extraction period; DateCreated =
  generation date.
- Mandatory-field doctrine: `*` fields mandatory; `**` conditionally mandatory; **all
  other fields mandatory whenever the data exists in the application** — enforced
  aggressively (due date ⇒ PaymentTerms; plate ⇒ DeliveryID; FR payment ⇒ Payment block).
- **No negative values** anywhere: sign is carried by debit/credit orientation; amounts in
  absolute value (the XSD's `minInclusive 0.00` makes negatives unserializable).
- The user can **never** choose what is exported.
- Hash/HashControl exported **exactly as stored at emission**. Integrated documents keep
  the origin's values and are additionally exported by the origin system.
- Invoicing files: one per establishment if decentralized, single if centralized.
  Self-billing edge case: header 1.2–1.8 = supplier data, TaxAccountingBasis "S",
  InvoiceStatus "S"; third-party billing: TaxAccountingBasis "E" + HeaderComment
  identifying the actual issuer.

**Master tables:**

- 2.2 Customer: every customer moved in the period **plus customers implicit in
  documents** (an invoice-only customer still gets a row, synthesized from the document's
  own snapshot; a master-lookup *error* is never treated as a miss). CustomerID unique;
  AccountID or `Desconhecido`; CustomerTaxID no prefix (generic 999999990); BillingAddress
  sub-fields (AddressDetail/City/PostalCode/Country) with `Desconhecido` allowed for
  unknowns and consumidor-final; ShipToAddress repeatable; SelfBillingIndicator 1/0.
- 2.3 Supplier: same shape **without** the `Desconhecido` allowances (ISO country only).
  SupplierTaxID and CustomerTaxID both Max 30.
- 2.4 Product: the moved catalog **plus implicit records** (an un-cataloged freight line
  still gets a row). ProductType P/S/O (portes, adiantamentos, alienação de ativos)/E
  (IEC)/I (other taxes/fees). ProductCode unique; **ProductDescription is the single
  source of truth — line descriptions equal it exactly**; ProductNumberCode = EAN or
  repeat of ProductCode; CNCode (8 digits)/UNNumber (4 digits) for customs/dangerous goods.
- 2.5 TaxTable: every regime used by any line, per fiscal space (§12).

**Line rules (all SourceDocuments):**

- No fiscally irrelevant lines (technical descriptions, assembly instructions, warranty
  terms). Lines exported in original document order; LineNumber sequential.
- UnitPrice net of line + header discounts, tax-exclusive, full precision; `0.00` when
  TaxBase is used (4.1) or when unvalued (4.2).
- TaxBase (4.1): unit taxable value not feeding NetTotal. ProductSerialNumber repeatable.
  CustomsInformation: ARCNo, IECAmount.
- DebitAmount ⊕ CreditAmount is an exclusive choice on every line, all tables (§4 rule 8).

**Totals, counters, per-table structure:**

- 4.1 counters: NumberOfEntries **includes** A/F documents; TotalDebit/TotalCredit
  **exclude** A/F. 4.2: NumberOfMovementLines includes A; TotalQuantityIssued excludes A.
  4.3 totals exclude A/F.
- 4.1 per-document elements: InvoiceNo; ATCUD (`CODE-NUM`); DocumentStatus (InvoiceStatus,
  date, Reason, SourceID, SourceBilling); Hash; HashControl; Period (1–12); InvoiceDate;
  InvoiceType; **SpecialRegimes** (three mandatory 1/0 indicators: SelfBillingIndicator,
  CashVATSchemeIndicator, ThirdPartiesBillingIndicator); SourceID; EACCode (CAE, when
  known); SystemEntryDate; CustomerID; ShipTo/ShipFrom (DeliveryID, DeliveryDate,
  WarehouseID, LocationID, Address); MovementStartTime (mandatory when the invoice itself
  serves as transport document) / MovementEndTime; Lines; DocumentTotals; WithholdingTax.
  (TransactionID exists in the schema but is N/A for an "F" file.)
- DocumentTotals: TaxPayable; NetTotal (excludes TaxTable taxes); GrossTotal (excludes
  withholding); Currency block (non-EUR only); Settlement (future discounts +
  PaymentTerms); Payment block — mechanisms enum:
  CC · CD · CH · CI · CO · CS · DE · LC · MB · NU · OU · PR · TB · TR.
- 4.2 specifics: MovementStatus F when a corresponding FT/FS exists; documents in 4.1 that
  also served as transport are **not** repeated in 4.2; ATDocCodeID (the AT transport
  code); MovementStartTime mandatory; CustomerID ⊕ SupplierID choice; unvalued totals 0.00.
- 4.3 WorkDocument: DocumentNumber; ATCUD; DocumentStatus (WorkStatus, date, Reason,
  SourceID, SourceBilling); **Hash; HashControl** (signed); WorkDate; WorkType; SourceID;
  SystemEntryDate; CustomerID; Lines; DocumentTotals. No SpecialRegimes, no ShipTo/From,
  no Settlement/Payment. Excludes docs belonging to 4.1/4.2.
- 4.4 Payment: PaymentRefNo; ATCUD; TransactionDate; PaymentType RC/RG; DocumentStatus
  (PaymentStatus, date, Reason, SourceID, SourcePayment); PaymentMethod (mechanism,
  amount, date, repeatable); SourceID; SystemEntryDate; CustomerID; Lines with
  **SourceDocumentID** (OriginatingON + InvoiceDate, repeatable) and PaymentTax (code may
  be NA); DocumentTotals (+ optional Settlement); WithholdingTax. **No Hash/HashControl.**
  Receipts still carry ATCUD + QR and print «Emitido por…» (§19).
- Referential integrity: every element referenced in 4.x must exist in 2.2–2.5; re-verify
  on the finished tree before writing the file (fail loudly before AT does).

**Key XSD facts** (authoritative over any prose):

- Monetary/decimal types: `xs:decimal`, `minInclusive 0.00`, **no fraction-digit facet** —
  5-decimal line values are schema-legal; the 2-decimal discipline on totals is a content
  rule.
- Hash = Max 172 (exactly the signature width). HashControl Max 70, pattern in §15.
  ATCUD Max 100. TaxExemptionReason min 6 / max 60. TaxExemptionCode `(M[0-9]{2})+`.
  ProductID pattern `[^/]+/[^/]+`. PostalCodePT `[0-9]{4}-[0-9]{3}`. PT NIF = integer
  100000000–999999999. DocArchivalNumber `[^ ]{1,20}`. TaxonomyCode 0–999. Email Max 254.
  ProductDescription min 2 / max 200. PaymentTerms free text Max 100 (a date string fits).
- Enums closed as listed in §3/§6/§12; TaxCountryRegion = ISO-3166 list + PT-AC + PT-MA;
  company address Country fixed "PT".
- Schema-level uniqueness: CustomerID, SupplierID, ProductCode, InvoiceNo, DocumentNumber,
  PaymentRefNo (duplicates are an XSD failure, not just a content anomaly).
- Serializer discipline [B]: element order is fixed by the schema — lock the serializer's
  field presence/order against the **parsed XSD itself** in a test, so the tree cannot
  drift.
- Validation tooling: requires an XSD 1.1 validator (`xs:assert`) in the test loop —
  e.g. Python `xmlschema`, Xerces-J, Saxon. A validator skip in tests must be loud.

## 19. Print layer

**Vias regime:**

- First emission ≥ 2 vias: «Original» (acquirer) + «Duplicado» (archive). **Transport
  documents: the law mandates 3 vias; the inspector's test demanded 4 — ship 4**
  (Original/Duplicado/Triplicado/Quadruplicado — RBC art. 5.º n.º 12).
- Reprint: «2ª Via» — never a new Duplicado.
- Annulled document: post-annulment reprint is still «Original» **plus an annulled-notice
  text**.
- Re-emitting an «Original» only with a DB audit record — when, which user, why — gated to
  a higher-permission user.

**Mandatory mentions:**

- Signed docs: `‹4 hash chars›-Processado por programa certificado n.º ‹NNNN›/AT` (chars
  at signature positions 1, 11, 21, 31; e.g. `AxAx-Processado por…`). Legible size; not on
  the first/last line or against edges.
- Receipts (unsigned): `Emitido por programa certificado n.º ‹NNNN›/AT`.
- The unique document ID (type code + series + numeric sequence) is printed; printed
  dates may use `AAAA-MM-DD` or `DD-MM-AAAA`.
- ATCUD per §16; «Este documento não serve de fatura» on all non-invoice
  client-presentable docs; recovered/integrated docs «Cópia do documento original» (+ §21
  grammar).
- Training mode: dedicated series; header shows the **software company's** data,
  «Documento emitido para fins de Formação», even on client letterhead.

**Per-type rules:**

- **FS prints only the client's NIF** — no name/address/other client data (CIVA 40.º).
- Client without NIF: the NIF line still appears — struck (`NIF: ---------`) or
  `NIF: Consumidor final` (both layouts accepted).
- Working documents (OR, PF, all 4.3): print **both** WorkDate **and** SystemEntryDate
  (date + hour + minutes) — DL 28/2019 art. 7.º. Label «Pró-forma» (not "Proforma").
- Conference-document minimum content (DL 28/2019 art. 7.º n.º 2): sequential number;
  date **and hour** of emission; supplier name + NIF; usual denomination and quantities;
  net price + tax, or tax-inclusive price; the express indication it does not constitute
  an invoice.
- Exempt lines referenced to the printed exemption mention (per-line footnote markers);
  same mechanism ties any rate to its product. Recommended VAT-summary order: isenta → 23 %.
- FR: payment mechanism/amount/date printed. GT: origin address printed as
  `PostalCode Locality`; exported optional fields (MovementEndTime) printed or dropped.
  FX: EUR countervalue in total + VAT summary.

**Layout constraints:**

- Multi-page: every page shows type designation, numbering, carried-forward/to-carry
  accumulated values, page n.º and total; base/tax/total apurations **only on the last
  page**.
- QR placement per §17. Pre-printed paper: the app still prints all fiscally relevant
  elements + mentions + issuer identity + document nature.
- **2ª via preserves original content** — later master-data changes never retroactively
  alter reprints (guaranteed by printing from persisted snapshots).
- Print-form design by the producer; user-created forms/types must be producer-validated;
  unvalidated forms unusable.

**Invoice content anchor (CIVA art. 36.º n.º 5)** — the element set the inspector checks
the print against: supplier and taxable-acquirer names/seats + NIFs (a non-taxable
acquirer's NIF is mandatory when they request it); quantity and usual denomination (with
elements to determine the applicable rate; packaging not transacted indicated separately);
price net of tax and other taxable-value elements; rates and tax due; justification for
non-application of tax (the §14 mention, verbatim); the availability/service/advance date
when it differs from emission date. With mixed rates, quantity/price/rate elements shown
**separately per rate**. Rectifying documents (n.º 6): date, sequential number, n.º 5 a)
elements, the reference to the rectified invoice and the elements being altered.
Self-billing (n.º 11): prior written agreement, proof of supplier acceptance, mandatory
mention «autofaturação». Reverse-charge cases: mandatory mention «IVA – autoliquidação».
Emission deadlines (n.º 1): 5th working day after tax due; 15th of following month for
intra-EU B2B; on receipt for advance payments.

## 20. Application-level requirements

1. **Access control:** per-user authentication; forced password change at first login;
   passwords non-empty; **an admin can never know or see a password**; admin-triggered
   resets force a change on next access.
2. **Backups:** mandatory-periodicity policy and/or ≥ 2 simultaneous DBs; backup copies of
   all processed data are a **legal obligation** (DL 28/2019 art. 11.º n.º 6).
3. **DB integrity:** control the DB and **count backup restores** (validated at
   open/close) to evidence out-of-application manipulation. DB unencrypted, retained for
   the archive period.
4. **Private-key protection, including during signing.**
5. **Alerts/confirmations:** unfilled mandatory SAF-T fields at processing; future-dated
   emission (and then no earlier-dated documents in that series); **system clock earlier
   than the last SystemEntryDate of ANY document (any series/type) ⇒ explicit
   confirmation before emitting**.
6. **Capacity:** retain all source documents; on nearing storage limits force external
   archive copies preserving SAF-T export capacity.
7. **Prohibitions:** user choice of signed/exportable types; recalculation over
   integrated/recovered documents (inherited errors stay evidenced); bypassing the §8
   freezes or §9 interlocks.
8. UI in **Portuguese** (multilingual acceptable with assured translation). Violation
   messages are operator-facing Portuguese.
9. Mobility solutions preserve numbering + last-signature-per-series after export to the
   integrator; never more than one active recovered document per manual/duplicate origin.
10. Multi-country builds enforce all rules for obligated PT taxpayers; signature,
    mandatory texts and immutability cannot be user-configurable off (FAQ21).
11. Training-mode and annulled documents are **recorded in the database** like any other.
12. E-invoicing (only if transmitting invoices electronically): authenticity + integrity
    via qualified electronic signature/seal (eIDAS) or EDI; plus chronological validation
    of messages, non-repudiation of origin/reception, non-duplication, and
    emitter-certificate revocation checks.
13. **Archive:** 10 years (or longer per tax-caducity rights), extending to technical
    documentation and backups. Paper archive stays in PT; electronic archive in any EU
    member state (outside EU only with prior AT authorization). Archive plan: an index in
    SAF-T format; archived document images named by Type + unique ID (`InvoiceType` +
    `InvoiceNo`, `MovementType`/`WorkType` + `DocumentNumber`, `PaymentType` +
    `PaymentRefNo`).
14. **Communications to AT:** establishments/equipment/certificate-number declaration via
    the Portal; e-invoice element communication (e-fatura) by day 5 of the following month
    (deadline moved by later law — re-verify before freeze); paperless-invoice
    dispensation requires certified program + acquirer NIF + real-time communication.

## 21. Recovery flows

**Manual (typography) recovery — SourceBilling "M". Mandatory in any commercializable
program** (the law obliges users to recover typography documents into the program; only
the program can satisfy the technical requirements).

- Dedicated series (annual+, numbering from 1 — one per manual series or a single one).
- A new document of the same type, **signed normally**, with the message using
  **document date = the manual document's date**; HashControl grammar per §15; printed
  expression **«Cópia do documento original - FTM abc/1»** (key version omitted from
  print); the normal certificate mention also printed. References cite the **manual**
  series/number.
- Behavioral rules the AT tests:
  1. Elevated permissions (e.g. store manager).
  2. Preferably a separate screen from normal emission.
  3. Values may be auto-calculated **but every inserted datum — line and total values
     included — must remain editable**: paper documents contain human errors and those
     errors **must be reproducible**. The flow bypasses calculated-totals invariants; the
     signature signs whatever total the paper shows (typed totals pass through the §11
     lexical renderer, never recomputation).
  4. Recoveries need **not** be date-sequential — a later recovery may carry an earlier
     date. Chronology checks and fiscal-year containment are suppressed for this flow;
     the §20 clock-alert semantics must not block it.
  5. For a manual document «F/23»: validate series «F» (§7 identifier rules), number
     23 > 0, and **block any second recovery of the same document**.

**Duplicate recovery after backup loss — SourceBilling "M".**

- On program error/anomaly: **close (encerrar) every series in use** — mandatory,
  otherwise new emissions would collide with the numbers to be recovered — restore the
  last backup, create new recovery series (one per damaged series or a single one,
  annual, starting at 1).
- Re-enter lost documents from their printed duplicates, **signed normally** with
  document date = the duplicate's date (may differ from system date — the clock alert
  fires by design); HashControl grammar per §15; printed
  **«Cópia do documento original - FTD FA abc/1»**. References cite internal code +
  series/number of the duplicate.
- Behavioral rules: elevated permissions; separate screen; the damaged series stays
  closed; recoveries **must be date-sequential** (each ≥ the previous — deliberately the
  opposite of the manual flow); validate internal code against the program's own pattern,
  series per §7, number > 0; **refuse documents that still exist in the DB** (not lost in
  the restore); block re-recovery.

**DB-safeguards answer frame** (for the §2 AT question): name the loss causes (program
anomaly, inadequate policies, viruses, insider threats, power events, human failure,
corruption, untested backups, ransomware, server theft) and answer in ISO/IEC 27001-aligned
vocabulary: accredited hosting, backup strategy distinguishing full/differential/
incremental and local / external-media / geographically separated off-site or cloud tiers.

## 22. Integration from other systems (SourceBilling "I")

- The integrator **never recalculates or modifies**: original unique IDs kept; series and
  types cannot mix origins; integrated docs live in their original, distinct series as
  copies; **Hash copied unchanged**; HashControl `‹origin cert›.‹keyver›`; no signer call
  ever; no QR; tax summary pinned to zero; the chain head advances dates only (origin
  sequences must be unique, contiguity not required).
- Print: «Cópia do documento original». Exemptions on integrated lines resolve at the
  **origin** date against reference data, never the tenant's catalog.
- Uncertified origin (prose prescribes HashControl «Não certificado», Hash `0`): the XSD
  pattern rejects the literal ⇒ **block uncertified-origin integration entirely** until AT
  clarifies (§42). No reprint by the integrator either way.
- APIs never sign: API-created invoices enter unconfirmed and are confirmed (and signed)
  by the application (FAQ28) — structurally satisfied by the draft/emit split (§27).

## 23. Certification test dataset (resubmission requirements)

1. Fix all reported anomalies and redo **every** test point; send PDFs + SAF-T to the
   inspector's email.
2. Guia de remessa/transporte with **4 vias** in one PDF.
3. A **multi-page document** exercising the §19 layout rules (per-page numbering, carried
   totals, apurations on last page).
4. Simulated **manual recovery** of two documents: **série F, n.º 23, 02-01-2026** and
   **série D, n.º 3, 01-01-2026**.
5. Simulated **duplicate recovery** of two documents absent from a restored backup —
   recovered document date = the original's date.
6. Answers to the two AT questions (§2); CAE 46501/47401 if commercializing.
7. Dataset realism: coherent NIFs; InvoiceDate↔SystemEntryDate gaps within the 5-working-
   day window; no dead types; every optional-but-known field populated.

---

# Part III — Architecture that guarantees the contract [B]

## 24. The append-only ledger

The legal regime *is* an append-only ledger; model it as one. Per-(document kind, series)
hash-chained ledgers to which exactly **five** things can ever be appended: an issued
document; an integrated copy (an issued document with a different source); a status
change; a print record; a series-state transition.

**A draft is the only mutable thing in the system, and it lives outside the ledger.** The
emission transaction is the single choke point where number assignment, clock capture,
signing, ATCUD and QR generation happen atomically.

**Sealed records.** Issued documents, status changes and print records must be
unconstructible outside the ledger module — no code path, including tests, may fabricate
one; tests emit through the real emitter. Use whatever privacy mechanism the target
language has (opaque types, module privacy, private constructors); the requirement is
that fabrication is a compile error, not a review catch.

**Aliasing discipline.** Any mutable data crossing into or out of a sealed record is
deep-copied at the boundary — a caller retaining a reference into a sealed record could
silently mutate *signed* content. Free in immutable-by-default languages; systematic and
load-bearing in mutable-reference languages. The decimal type never mutates internal
state after construction, killing the bug class at the bottom of the stack.

**Series head = value-type state machine.** Transitions (inhibit, close, roll-year,
advance) produce new values; a transition becomes real only when a store transaction
commits it. A future-dated latch sticks until the year rolls. No destructive method
exists. A head tracks: internal type code + series + document kind; purpose; ATCUD
validation code; fiscal year; next sequence; last hash; last document date; last
SystemEntryDate; the future-dated latch; state (Active / Inhibited / Closed).

## 25. Validated value objects (parse, don't validate)

Every fiscal scalar is a type whose only public constructor is a validating parser;
invalid values never exist, so downstream code carries zero checks. Inventory:

| Type | Validation |
|---|---|
| NIF | 9 digits d1…d9; check digit: c = 11 − ((d1·9 + d2·8 + … + d8·2) mod 11), d9 = 0 when c > 9, else c. Generic consumer `999999990` exposed as a function/constant |
| Series ID | §7 rules + regex; `AT` prefix rejected |
| Internal type code | program's own pattern; never reused across document types |
| DocNo | `CODE SERIES/SEQ` grammar |
| ATCUD | `ValidationCode-Seq` |
| Validation code | §16 charset and length |
| Date / DateTime | valid calendar values; rendered without timezone/ms; a zero time is a crash (broken clock = broken infrastructure) |
| Reference | three legal grammars (document / manual origin / duplicate origin); free strings unconstructible |
| Status | per-family closed sets (§6); illegal pairs unrepresentable |
| Document kind | closed enum + spec table (family, orientation, signed, rectifying, transport…). Dead kinds have no representation; all behavioral routing reads this table — no subclass hierarchy |

**Snapshots as legal proof.** Documents embed customer/product *snapshots*; the snapshot
type's only constructor takes the master record. Line-description divergence (§4 rule 7)
becomes unrepresentable, and 2ª-via fidelity survives master-data edits.

**CP-1252 gate.** Representability in Windows-1252 is validated on every free-text input
at draft build time — an unencodable character blocks the draft, not the export years
later. Free text is normalized to single-line (CR/LF and control characters stripped)
before validation.

## 26. The emission pipeline (normative)

One pipeline for every family; per-family behavior is configuration (variant/strategy
objects — "variant lacks the capability" rather than boolean flags: receipts skip signing
because their variant has no hash-control producer). All steps inside one store
transaction:

1. **Re-validate the draft** — the emitter never trusts the caller built it honestly;
   the draft re-runs its constructor's blocking checks under the lock.
2. Enter the emission transaction; the store acquires, in fixed order: emitter lock
   (SHARED) → origin claim (recovery/integration only) → series head → every referenced
   document in canonical order. Deadlock-free by ordering.
3. Head guards: series Active; series purpose admits this emission; kind matches series;
   ATCUD validation code present (except integration-purpose series); document date
   within the head's fiscal year (skipped for the two recovery purposes).
4. Chronology: document date ≥ head's last date (suppressed **only** for manual
   recovery). Future-dated → **Confirm**.
5. **One clock capture**, reused pipeline-wide. Clock regression against the store-wide
   max SystemEntryDate and the head's last entry → **Confirm**. More than 5 working days
   since document date → **Alert** (working days = Mon–Fri; the reference uses no holiday
   calendar).
6. Post-capture family checks (movement / invoice-as-transport: start time ≥ capture).
7. Resolve and load every referenced document **under the held locks**; run the §9
   interlocks. A resolution error (e.g. cross-kind origin ambiguity) fails the whole
   transaction closed; a missing target is an ordinary Block. **7b:** an ND materializes
   its lines here from the locked target (target line's product/quantity/tax + the
   correction's unit-value delta through the §11 chain with zero discounts).
8. Recovery gates (§21 uniqueness and DB-existence checks).
   **Checkpoint:** evaluate all collected violations against the caller's confirmations.
   Confirms are **re-derived under the lock** — a stale grant can never authorize state
   the operator didn't see.
9. Mint identity: next sequence, DocNo, ATCUD.
10. **Render GrossTotal once** — recovery flows: lexical render of the typed total;
    otherwise fixed 2-decimal. Persist this string on the record.
11. Sign (skipped for receipts): message per §15 from document date + capture + DocNo +
    rendered gross + head's last hash. Signer failure aborts everything. Post-sign, the
    composed HashControl must fit the 70-char XSD cap.
12. Assemble the QR input from the stored tax summary + signature chars + creation
    status; build and persist the payload.
13. Status propagation: every guia/work document this document bills gets its `→F`
    transition **in the same commit**.
14. Assemble the sealed record; advance the head (new last hash — unchanged for receipts
    — dates, future-dated latch).
15. Atomic commit: record + transitions + head swap in one durable write, or nothing.
    Preview: run everything, then abort deliberately — same pipeline, zero side effects,
    results marked as non-facts.

**Cross-cutting rules:** references are keyed by the pair (reference kind, rendered
string) — never the string alone (a document ref `FA abc/1` and a duplicate-origin ref
`FA abc/1` render identically but name different targets). Exactly one of {computed
lines, edited lines, receipt lines, unvalued lines} is populated on any issued document;
print, SAF-T, NC caps and ND materialization all switch on this dichotomy.

## 27. Violations and errors

- One violation value: `{Code, Section, Severity, Message}`. Message in Portuguese
  (operator-facing); Code stable and greppable (e.g. `NC_OVER_REMAINING`); Section cites
  the governing section of this specification — keep the two-way rule⇄enforcement index.
- Severities: **Block** (must not happen); **Confirm** (proceeds only with an explicit
  operator confirmation, recorded as violation code → confirming user's SourceID,
  re-derived under the emission lock); **Alert** (surfaced alongside success).
- Uniform result shape: `(result, alerts, error)`; error is a violations error, a
  confirmation-required error, or infrastructure. Annul and Integrate produce Blocks only.
- **Bug vs input:** a condition a correct caller cannot cause is a program bug —
  crash/assert with a message saying why it is unreachable (zero clock, truncating
  render). Operator/data conditions are violations. Apply the test to every new check.
- **Rule placement** — decide the layer before writing a check: data-shape rules → the
  value-object parsers (§25); ledger-independent rules → the draft constructor (and its
  re-validated blocking subset); rules needing locks or other documents → the emission
  pipeline (§26); storage-independent store semantics → the shared store layer (§28).
- The draft/emit split satisfies FAQ28 structurally: building a draft signs nothing;
  only the explicit issue action reaches the signer.

## 28. Store contract and concurrency

Six transaction shapes, each atomic — commits everything or leaves no trace:

| Shape | Lock scope | Used for |
|---|---|---|
| Emission | emitter SHARED → origin claim → head → refs (sorted) | issue / recover / integrate |
| Doc | one document | annul (serializes against anything referencing it) |
| Print | one document | vias decision + print record, atomically |
| ATDocCode | one document | recording the AT transport code |
| Emitter | emitter EXCLUSIVE | series lifecycle (open / roll / inhibit / close-all) |
| Export | one committed read snapshot | SAF-T projection, verification, reporting |

- The emitter read-write lock is load-bearing: emissions hold it shared, series lifecycle
  exclusive — so the duplicate-recovery precondition "every series is closed" cannot be
  invalidated by a concurrent series-open before the recovery commits.
- Linearizability: the max-SystemEntryDate read inside an emission reflects every
  previously committed emission (drives the clock-regression Confirm).
- **Loud backstops at the store layer**, even though the emitter gates them upstream
  (defense in depth against a buggy adapter): unique sequence per head, unique DocNo,
  at-most-one active recovery per origin, status transitions must target existing
  documents.
- Storage is **append-only**: no UPDATE/DELETE on record tables (SQLite: abort triggers;
  Postgres: equivalent), every head transition mirrored to an append-only heads log.
- The domain layer holds no locks and no mutable global state; the emitter object is
  reentrant; all concurrency lives behind the store contract.
- Multi-tenant store: shared schema + tenant column + **row-level security with FORCE**
  (never application-side WHERE filtering); every transaction sets the tenant context
  first; migrations refuse superuser / RLS-bypassing roles; the per-tenant emitter lock is
  a row taken FOR UPDATE; export snapshots run REPEATABLE READ READ ONLY.
- Anti-drift: storage-independent semantics (commit validation, deterministic series
  ordering, origin-grammar parsing, origin resolution, rectifier matching) live in **one**
  shared layer with a per-store lookup callback; SQL text shared across engines where
  possible (fork only where dialects force it, e.g. upserts).

## 29. Persistence, rehydration, verification

- Sealed types are reconstructed from storage through **guarded, re-validating doors**
  that re-check at read time what emission enforced at write time: hash shape ⟺ source
  kind (172 chars own-signed / `"0"` uncertified-origin data / empty receipts);
  HashControl grammar per source; ATCUD ⟺ DocNo coherence; per-family status legality;
  non-empty QR for own signed docs; byte-match of the persisted rendered gross.
- The line-level door re-derives arithmetic identities (`Amount = UnitPrice × Qty`, VAT
  algebra) but **not** the discount chain — chain inputs are never persisted, only their
  rounded results.
- The per-document tax summary is **recomputed on read** from the document date against
  the temporal rate table — except integrated documents, pinned to zero. Persisted
  exemption mentions round-trip unchanged regardless of later table versions.
- Codec shape [C]: chain-critical scalars as columns with byte-identity pinned across
  persist/reopen (hash, hash control, QR payload, rendered gross); the rest as one
  structured body; explicit DTOs.
- **Chain verification** replays every signature over the whole chain (boot-time and/or a
  dedicated command). Chain identity is the *pair* (type, series), never a concatenated
  string (`"FT"+"AB"` vs `"FTA"+"B"` must not merge). Integration series verify
  uniqueness only, never contiguity.
- Honest ceiling (accepted design position — state it in the port's docs too):
  rehydration catches malformed rows; a plausible *forged* row is caught only for signed
  families, by chain replay. Receipts and integrated copies rest on append-only storage
  and process controls. Immutability past the process boundary is detected, not prevented.

## 30. One value, one rendering

Wherever one datum appears on multiple surfaces (signature, QR, SAF-T, print), compute
and render it **once** and thread the rendered artifact through as bytes. The persisted
rendered gross is the canonical case: the SAF-T value is byte-identical to what was
signed *because it is the same string*, not because two formatters agree. Formatting a
value that was already rendered elsewhere recreates the bug class this architecture
exists to prevent.

Three renderers with distinct meanings — port the distinction: fixed-2 (crashes if the
value is not 2-decimal-closed — a program bug surfacing loudly); canonical-minimal-digits
(the §11 fixture value 0.45144 renders `"0.45144"`); round-2-then-fix-2 (the sanctioned
display projection, mirroring the QR assembly).

---

# Part IV — Platform and delivery surfaces

## 31. Orchestration layer

- **Prepare** (per family): normalize free text (single-line, control chars stripped),
  resolve customer/product IDs into snapshots, parse operator FX input, build the draft.
  Failure = violations, no draft. Success = draft held in memory under a UUID,
  unconfirmed.
- **Issue** (draft ID, principal, confirmations): claims the draft, runs §26. The
  confirmation round-trip: a confirmation-required error carries the Confirm violations;
  the client retries with grants; grants are re-derived under the lock. **Discard** frees
  a draft; drafts never outlive their purpose.
- Annul and Integrate: direct calls, Blocks only. Export: one read snapshot → SAF-T tree
  → serializer. An audit port records re-original print grants (who/when/why).
- Receipt gate: an RC blocks at Prepare if any line's tax code is empty or NA (mirrors
  the SAF-T RC-only condition; RG deliberately excluded).
- The fiscal layer takes a **pre-authenticated principal** — the auth boundary sits above
  the certified core, never inside it.
- Operator input errors (bad NIF, bad address) are distinguished from infrastructure
  faults as a typed input-error channel (→ 422 vs 500 at the HTTP layer).

## 32. Control plane

- Config split per §5: tenant half (stored per tenant, JSON: `nif`, `company_name`,
  `company_id`, `company_address{detail,city,postal_code}`) + software half (deployment
  file: `producer_name`, `producer_address`, `product_company_tax_id`, `product_id`,
  `product_version`, `certificate_no`). Merged and validated into the fiscal emitter
  config at tenant provisioning, at config update, and at server startup (probe).
  Optional emitter fields (fiscal-year start month/day, art. 53.º regime,
  retail/ambulant flag, establishment, business name, contacts) default to zero values.
- Users: passwords exist only as **argon2id** encodings past the hashing call. Admin-blind
  reset: mint a one-time temporary password, store only its hash, force change on next
  login, return the plaintext exactly once. Password change or reset revokes **all** the
  user's tokens. A must-change-password session is blocked from every operation except
  the password change itself.
- API keys and users share the fiscal SourceID never-reuse namespace (§8): provisioning
  writes the platform row **and** the fiscal-plane source-ID claim in one transaction; any
  collision (including with retired IDs) fails the whole provisioning with a conflict.
- **Idempotency machine** (all emitting endpoints): claim (tenant, key) with a hash of
  the request body before any work. Fresh claim → proceed. Completed same-key-same-body →
  replay the stored response verbatim, **including stored 4xx**. Same-key-different-body →
  conflict. A 5xx outcome deliberately stays **pending** — after a 500 the client cannot
  know whether the document was emitted, so a blind retry must not replay a guess. Rows
  swept after 48 h by a cron command; no in-server background jobs except the AT drainer.
- Platform store shape [C]: one method per atomic operation (single inserts, not
  pipelines). Platform tables sit outside row-level security (login and provisioning run
  before a tenant context exists) — keep that exemption list explicit and tested.
- Registry: one fiscal app instance cached per (tenant, config-version); a config-version
  bump invalidates the cache, not time. Reads bypass the app façade straight to the store
  — no invariant lives on the read path.
- Tenant enumeration for sweeps (verification, draining) uses an identity-only projection
  so one tenant's corrupt config JSON cannot abort the sweep for the rest.

## 33. HTTP API

**Semantics:**

- One error shape on the wire: `{Status, Title, Detail, Code, Violations}`; even
  framework schema-validation failures are remapped into it. 422 carries the violations
  split Blocking / Confirmable (Blocks never confirmable); a confirmable-only rejection
  is retried with the identical body plus `"confirmations": [codes]`. 500s are opaque
  (`incident ‹correlation id›`) — details go to structured logs only.
- Emission is **stateless Prepare→Issue in one request**; the draft registry never
  outlives a request (discard on every failure path); a confirm retry re-prepares. One
  shared emission runner owns discard-on-failure, confirmation threading and error
  mapping across all emitting handlers; a bare rule error surfacing from inside the
  emission lock (e.g. the §21 second-recovery block) maps to 422, never 500 — the
  inspector tests that flow.
- All emitting POSTs honor an `Idempotency-Key` header per §32.
- Auth: bearer tokens (login) and API keys; tenant provisioning is guarded by a
  constant-time root API key; admin-gated routes: users, API keys, tenant config, AT
  credentials, series lifecycle, recovery, integration.
- Missing-document lookups map to 404 via a typed sentinel, never message-text matching.
- Recovery inputs carry typed totals as **raw strings** end-to-end (§21 rule 3);
  integration inputs make certificate/key-version/origin-hash optional so their absence
  triggers the §22 fail-closed block as doctrine, not a transport error.
- OpenAPI document served; list endpoints paginated.

**Surface** (v1):

| Area | Operations |
|---|---|
| Health | `GET /v1/health` |
| Auth | `POST /v1/auth/login` · `POST /v1/auth/logout` · `POST /v1/auth/password` · `POST /v1/auth/reset-by-email` |
| Tenants | `POST /v1/tenants` (root key) · `GET /v1/tenant/config` · `PUT /v1/tenant/config` (optimistic version) |
| Users | `POST /v1/users` · `GET /v1/users` · `POST /v1/users/{id}/reset-password` · `POST /v1/users/{id}/deactivate` |
| API keys | `POST /v1/apikeys` · `GET /v1/apikeys` · `POST /v1/apikeys/{id}/deactivate` |
| Masterdata | `PUT/GET /v1/customers/{id}` · `GET /v1/customers` · same for `/v1/products` and `/v1/suppliers` |
| Series | `POST /v1/series` (with validation code) · `GET /v1/series` · `POST /v1/series/{kind}/{id}/inhibit` · `…/roll-year` · `POST /v1/series/close-all` |
| Emission | `POST /v1/documents/sales` · `…/movements` · `…/work` · `…/debit-notes` · `…/receipts` · `POST /v1/documents/preview` |
| Documents | `GET /v1/documents` · `GET /v1/documents/{no}` · `POST /v1/documents/{no}/annul` · `POST /v1/documents/{no}/print` |
| Recovery | `POST /v1/recovery/manual` · `POST /v1/recovery/duplicate` |
| Integration | `POST /v1/integrations` |
| Export | `GET /v1/saft` |
| AT comm | `PUT/GET/DELETE /v1/at/credentials` · `POST /v1/series/at-register` · `POST /v1/series/{kind}/{id}/at-finalize` · `…/at-cancel` · `POST /v1/documents/{no}/at-transport` · `GET /v1/documents/{no}/at-status` · `GET /v1/at/comm` |

## 34. AT webservices

- SOAP clients for the AT endpoints: series communication (register/finalize/cancel),
  invoice communication, transport-document communication. The full seriesWS contract is
  an open gap (§42) — validation codes remain operator-typed for certification v1.
- Per-tenant AT portal credentials in an AES-256-GCM vault (master key from the
  environment); credential presence checkable without decryption; the AT endpoints answer
  503 when the vault or a real client factory is absent — a null client must never
  fake-communicate real documents.
- Document communications are queued as rows; a background **drainer** sweeps due rows
  per tenant on an interval: bounded batch, exponential backoff (1 m, 2 m, 4 m … capped),
  per-document outcomes recorded on the rows, infrastructure errors logged. The drainer
  starts only when both the vault and a real factory exist.
- The certificate number sent to AT comes from the software config (deployment), never
  tenant data; non-numeric certificate config is an error, never silently 0.

## 35. Binaries and operations

- Single-tenant CLI: `selfcheck` (boot + full chain verification + counts) and a scripted
  end-to-end `demo`; config = fiscal emitter JSON + reference-data paths.
- Multi-tenant API binary, four subcommands: `migrate` (schema migrations, run as the
  schema-owner role); `serve`; `verify` (chain replay — one tenant or sweep-all,
  continuing through per-tenant failures so one bad chain cannot hide the rest); `sweep`
  (idempotency GC, 48 h).
- Environment contract: database URL; listen address; **signing-key file** (PKCS#1 PEM,
  `openssl genrsa -traditional 1024`; required by serve + verify); **software-config
  file** (§32; required by serve, validated at startup); root API key (serve); DB pool
  cap; AT toggles (environment name, vault master key — 32-byte AES-256 — and drain
  interval; unset leaves AT integration off).
- Production shape: structured JSON logging; explicit HTTP server timeouts; signal-driven
  graceful shutdown with a drain window; bounded DB pool; two-role Postgres split (schema
  owner for migrations; DML-only, RLS-subject role for the application — RLS means
  nothing under a superuser). Run `verify` and `sweep` from cron.

---

# Part V — Build and verify

## 36. Definition of done

A port is done when, in order:

1. Every fixture in §37 reproduces exactly (digit-exact / byte-exact as stated).
2. Every store implementation passes the conformance semantics of §38.
3. A full §23-shaped dataset emitted through the real emitter exports a SAF-T file that
   validates against `saftpt1.04_01.xsd` under an XSD 1.1 validator — with encoding
   checks and lexical sweeps (date formats, no negative money) ordered before validation
   so a validator skip cannot mask them, and any skip loud.
4. The behavioral probes hold live: the §9 interlocks block; the §21 recovery rules
   behave (including the opposite date rules); annul-then-reprint still prints `E:N` and
   an annulled notice; concurrent emission keeps numbering gapless; emission without a
   validation code is blocked; FS above cap is blocked; ND quantity/foreign-product
   corrections are inexpressible or blocked.

Reference identities used by the fixtures (never change them): emitter NIF `570023262`,
certificate `9999`.

## 37. Fixture catalog (the portable contract)

**1. Money-chain worked example — digit-exact, all intermediates.**
Input: global discount 10 %; line 1: qty 100 × PU 0.55 @ 23 %, line discount 8.8 %;
line 2: qty 1 × PU 45.00 @ 23 %, no line discount. N = 5.

| | Line 1 | Line 2 |
|---|---|---|
| E | 0.0484 | 0 |
| F | 0.5016 | 45.00 |
| G | 0.05016 | 4.5 |
| H | 0.45144 | 40.5 |
| UnitPrice | **0.45144** | 40.5 |
| CreditAmount | **45.144** | 40.5 |
| SettlementAmount | **9.856** | 4.5 |
| LineVAT | 10.38312 | 9.315 |

Totals: **TaxPayable 19.70 · NetTotal 85.64 · GrossTotal 105.34**.

**2. Signature message golden** (empty PrevHash ⇒ trailing `;`):
`2010-05-18;2010-05-18T11:22:19;FAC 001/14;3.12;`

**3. QR vectors — byte-exact (inspector-corrected).**

```
A:570023262*B:234416564*C:PT*D:FS*E:N*F:20260304*G:FS A/1*H:BCDFGH38-1*I1:PT*I3:18.50*I4:1.11*N:1.11*O:19.61*Q:ncQR*R:9999
A:570023262*B:539915661*C:PT*D:FT*E:N*F:20260426*G:FT A/1*H:BCDFGH37-1*I1:PT*I7:24.00*I8:5.52*N:5.52*O:29.52*Q:njXW*R:9999
A:570023262*B:539915661*C:PT*D:FT*E:N*F:20260417*G:FT A/3*H:BCDFGH37-3*I1:PT*I2:100.00*I3:18.50*I4:1.11*I5:12.50*I6:1.63*I7:55.00*I8:12.65*N:15.39*O:201.39*Q:IPKT*R:9999
A:570023262*B:539915661*C:PT*D:GT*E:N*F:20260426*G:GT A/2*H:BCDFGH44-2*I1:0*N:0.00*O:0.00*Q:CBMv*R:9999
```

Vector 1: FS, reduced rate only. Vector 2: FT annulled after emission — **E stays N**.
Vector 3: FT with all four rate buckets. Vector 4: GT não valorizada.
One more from the official QR spec (FS, cash, 141 chars → version-9 floor at ~30 mm):
`…*D:FS*…*I1:PT*I7:0.65*I8:0.15*N:0.15*O:0.80*Q:YhGV*R:9999*S:NU;0.80` — and a pró-forma
carries `D:PF` with a full I-block and no P/S fields.

**4. Rounding vectors.** Cascade: `10.144 → 10.14`; `10.145 → 10.15`;
`10.1446 → 10.145 → 10.15` (single-step half-up gives 10.14 — the differential trap;
harness: compare against naive `round(x,2)` on >2-decimal inputs).
Lexical: `1000 → 1000.00`; `0.1 → 0.10`; `.1 → .10`.

**5. ATCUD composition.** Doc `ABC BNH/4561` + code `TES123TE` → `TES123TE-4561`.

**6. Recovery simulations** (the certification dataset of §23): manual recovery of
**série F, n.º 23, 02-01-2026** (out-of-order dates permitted) and **série D, n.º 3,
01-01-2026** — byte-exact HashControl (`‹kv›-FTM F/23` grammar) and printed
«Cópia do documento original - FTM F/23» mentions.

**7. HashControl vectors.** Own `1`; integration `9999.1`; manual `1-FTM abc/1`;
duplicate `1-FTD FA abc/1` — all matching the §15 XSD pattern and ≤ 70 chars;
«Não certificado» must **fail** the pattern.

**8. Series-identifier vectors.** Accept: `A`, `FT2026`, `a.b-c_d`. Reject: `ATX1` (AT
prefix), `.abc`, `abc.`, `a..b`, `açores`, any 36-char identifier.

**9. Print mentions.** `‹c1›‹c11›‹c21›‹c31›-Processado por programa certificado n.º ‹NNNN›/AT`;
receipts `Emitido por programa certificado n.º ‹NNNN›/AT`;
«Este documento não serve de fatura»; «Documento emitido para fins de Formação»;
«Cópia do documento original - FTM abc/1» / «… - FTD FA abc/1».

**10. Exemption mentions.** All 33 M-code mentions of §14, byte-exact against the shipped
table (pin the full table, not a sample).

## 38. Store conformance semantics

One conformance suite run against every store implementation — passing it is the
definition of done for a store:

- Atomicity: every transaction shape commits everything or leaves no trace, including
  crash injection between sign and commit (no record, no head advance, no gap).
- Loud backstops: duplicate sequence, duplicate DocNo, second active recovery of one
  origin, transitions targeting missing documents — rejected at the store even though the
  emitter gates them upstream.
- Snapshot consistency: export sees one committed snapshot.
- Clock linearizability: max SystemEntryDate reflects all committed emissions.
- Gapless numbering under concurrent emitters.
- Race scenarios (the inspector probes these live): concurrent NCs over one invoice;
  annul-vs-NC in both orders; billing-vs-annul; same-origin recovery from two series
  concurrently.
- Emitter-exclusive semantics: series lifecycle excludes concurrent emissions (the
  duplicate-recovery precondition).
- Restart conformance (durable stores): persist → close → reopen → **byte-identical**
  read-back of hash / hash control / QR payload / rendered gross + full chain
  verification.

## 39. Target-language capability checklist

Verify **before** committing to a stack — several modern platforms refuse the legacy
crypto this law mandates:

1. **RSA-SHA1 · PKCS#1 v1.5 with 1024-bit keys, sign and verify.** Current crypto stacks
   deprecate or hard-block SHA-1 signatures and sub-2048-bit keys (FIPS builds, OpenSSL 3
   defaults, .NET/Java policy). A supported, non-hacky path is required. Do not
   modernize — the law fixes the parameters.
2. **Exact decimal arithmetic** on arbitrary-precision integers. No IEEE floats anywhere
   on money paths, including JSON parsing (money as strings) and rendering.
   Multiplication exact by construction — give a "rounding multiply" no API to exist.
3. **Windows-1252 encoder** with a strict error (rune + offset) on unmappable characters,
   plus the input-time representability check.
4. **XSD 1.1 validator** wired into the test loop.
5. **XML serializer with controlled element order** and a custom encoding declaration;
   element order locked against the parsed XSD by a test.
6. **QR library** allowing forced ECC level M, byte mode, and a minimum-version floor (9).
7. argon2id; AES-256-GCM; constant-time comparison.
8. UTF-8 byte-exact handling: signatures are computed over bytes, not "strings".
9. Second-resolution timestamps rendered without timezone or milliseconds.
10. SQLite and/or Postgres; RLS + FORCE is DB-side and ports as-is; append-only
    enforcement via triggers.
11. A privacy mechanism for sealed types (§24) and, ideally, a mechanical way to keep the
    domain core dependency-free (the reference enforces stdlib-only imports by test).

## 40. Reference-implementation idioms and their translations [C]

- Package-sealing / unexported fields → any unconstructibility mechanism.
- Struct-of-closures emission variants → strategy/variant objects; keep "variant lacks
  the capability" over boolean flags.
- "No generics over document families" is a design rule, not a language limitation: four
  separate draft types with shared functions, unified only inside the emission pipeline.
  Do not build an abstract Document hierarchy.
- Per-boundary clone helpers → systematic aliasing defense; dissolves under immutable
  data structures.
- Callback transactions (`withEmission(fn)`) → any transactional scope handing `fn` a
  capability-scoped handle (the handle exposes only reads that are safe under the held
  locks).
- Typed not-found sentinel → typed error matching, never message text (a driver's
  "prepared statement not found" must never read as a missing document).
- Panics reserved for unreachable states; violations for operator conditions (§27).
- Composition root: one hand-written wiring point per binary; no DI framework required.
- Violation messages stay Portuguese; codes and section citations stay stable.

## 41. Deliberate exclusions — do not build

v1 decisions, not omissions: multi-target NC/ND; recovery of NC/ND/FR/movement kinds;
self-billing emission (blocked at draft build); uncertified-origin integration (§22);
line-level references; multi-establishment; automated series communication (validation
codes operator-typed until the seriesWS gap closes); dual builds or user-configurable
compliance (forbidden outright).

## 42. Open gaps — do not freeze answers

The spec is deliberately open here; the port must not invent answers:

- seriesWS SOAP contract + AT producer test environment (validation codes stay
  operator-typed meanwhile).
- DL 147/2003 transport regime full text (prior communication, ATDocCodeID detail).
- Signature-invalidity root-cause verification: before assuming anything else, verify the
  §15 parameters with `openssl dgst -sha1 -verify` against the exact public key submitted
  with the Modelo 24.
- Current AT certification FAQ compendiums (inspector doctrine evolves there).
- Uncertified-integration HashControl (§22 — currently fail-closed; ask AT how a
  non-certified origin should serialize).
- QR field N vs Σ per-rate I-fields: independently rounded buckets can diverge ±0.01;
  currently N = document TaxPayable (spec letter); confirm tolerance.
- Receipt QR `Q:0` convention (receipts have no hash; no documented alternative).
- IS fixed-verba × quantity (currently applied once per line, not multiplied).
- Tax-inclusive pricing back-computation (no AT fixture; derive symmetrically, flag it).
- e-fatura communication deadline (day 5 of following month — moved by later law;
  re-verify before freeze).
- Receipt QR rate buckets; integrated-document QR treatment; re-recovery semantics
  (recovering an already-recovered document) — derived choices queued for the AT
  videoconference.
- CIVA art. 78.º verbatim (NC-regularization proof mechanics, §9).

House style for all of them: **the strict reading ships and the question goes to the AT
videoconference.** Fail closed outranks convenience; this specification outranks
intuition; the fixtures outrank everything except the XSD.
