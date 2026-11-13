#ifndef BF_CORE_H
#define BF_CORE_H

/* The native core: no Zend headers in any bf_*.c. PHP is reached only through bf_host. */

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#define BF_MAX_SCHEMA_COLS 1024
#define BF_MAX_FILE_COLS   4096
#define BF_MAX_PREDS       64
#define BF_MAX_NAME_BYTES  1024
#define BF_MAX_LITERAL     ((size_t)1048576)

typedef enum { BF_INT64, BF_FLOAT64, BF_BOOL, BF_STRING } bf_type;

/*
 * The only code that differs by ISA: each 64-byte block becomes a mask of the delimiter byte, LF, CR and ".
 * delim is one of , ; tab | (the plan admits no other byte).
 */
typedef void (*bf_masks_fn)(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out);

void bf_masks_scalar(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out);
#if defined(__x86_64__)
void bf_masks_avx2(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out);
#endif
#if defined(__aarch64__)
void bf_masks_neon(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out);
#endif

/* Set once in MINIT and read-only afterwards. */
extern bf_masks_fn bf_masks_auto;
extern const char *bf_masks_auto_name;
void bf_masks_init(void);

/*
 * SPEC 6.2: the end of a row past its last referenced column. buf and masks are the scan area, *blk and *cur the scan
 * position (the structural bits of block *blk not yet taken). Walks the blocks from there to the first LF, CR or quote
 * byte, counting the bits before it, which are all delimiters, by popcount. Returns that byte and moves *blk and *cur
 * past it, with *ndelim the delimiters counted, or NULL, changing nothing, when the area ends first. One function per
 * ISA, like the masks; NULL from bf_tail_for for the scalar masks, which keep the per-bit walk.
 */
typedef const char *(*bf_tail_fn)(const char *buf, const uint64_t *masks, size_t nmasks, size_t *blk, uint64_t *cur,
	size_t *ndelim);
bf_tail_fn bf_tail_for(bf_masks_fn masks);
/* Fewest fields left after the last referenced column for which a row uses it (the call costs about two blocks). */
#define BF_TAIL_MIN 8
/* NULL when the name is not compiled in or this CPU cannot run it. */
bf_masks_fn bf_masks_find(const char *name);
/* NULL-terminated, in preference order, every ISA built into this binary. */
extern const char *const bf_masks_compiled[];

bool bf_utf8_valid(const char *s, size_t n);

typedef enum {
	BF_P_IS_NULL,
	BF_P_NOT_NULL,
	BF_P_INT_EQ, BF_P_INT_NE, BF_P_INT_GT, BF_P_INT_GTE, BF_P_INT_LT, BF_P_INT_LTE, BF_P_INT_IN,
	BF_P_FLOAT_EQ, BF_P_FLOAT_NE, BF_P_FLOAT_GT, BF_P_FLOAT_GTE, BF_P_FLOAT_LT, BF_P_FLOAT_LTE, BF_P_FLOAT_IN,
	BF_P_BOOL_EQ, BF_P_BOOL_NE, BF_P_BOOL_IN,
	BF_P_STR_EQ, BF_P_STR_NE, BF_P_STR_IN
} bf_pred_kind;

/*
 * One predicate. A scalar literal is i, d, b or the bytes s and n. A set (the *_IN kinds, SPEC 5.7) is
 * sorted and distinct, with n elements: INT_IN n int64_t at set; FLOAT_IN n doubles at set in IEEE order,
 * -0.0 stored as 0.0; STR_IN n + 1 uint64_t offsets at set followed by the bytes, element k being the bytes
 * from off[k] to off[k + 1], ordered by length, then bytes; BOOL_IN bit 0 (false) and bit 1 (true) of i. For a set, b
 * says whether the list held null.
 */
typedef struct {
	bf_pred_kind kind;
	uint32_t col;       /* schema column */
	uint32_t slot;      /* decoded-value slot, assigned per run */
	bool b;
	bool null_match;    /* assigned per run: whether a NULL value passes */
	int64_t i;
	double d;
	const char *s;      /* stays valid for the predicate's owner */
	size_t n;
	const void *set;    /* stays valid for the predicate's owner */
	void *owner;        /* opaque to the core: the glue's reference on the bytes of s or set */
} bf_pred;

/* Aggregates of SPEC 5.8, at most 16 per call. */
#define BF_MAX_AGGS 16
#define BF_MAX_GROUP_KEYS 4

typedef enum { BF_AGG_COUNT, BF_AGG_SUM, BF_AGG_MIN, BF_AGG_MAX, BF_AGG_AVG } bf_agg_op;

typedef struct {
	bf_agg_op op;
	uint32_t col;       /* schema column */
} bf_agg;

/* One aggregate's running state: the int64 or float64 sum, minimum or maximum, and the non-NULL count. */
typedef struct {
	union {
		int64_t i;
		double d;
	} v;
	uint64_t n;
} bf_agg_state;

typedef struct {
	const char *name;   /* schema-owned, not NUL-terminated */
	size_t name_len;
	bf_type type;
} bf_col;

/* One decoded field value. String bytes live in the read buffer, valid for the current row. */
typedef struct {
	int64_t i;
	double d;
	const char *s;
	size_t n;
	uint8_t b;
	bool is_null;
	uint32_t id;         /* table runs: a dictionary column's id (0 NULL); in the padding, so the size is unchanged */
} bf_val;

/* ---- host ---- */

typedef enum {
	BF_READ_DATA,     /* *got > 0 bytes appended */
	BF_READ_EOF,      /* end of input, *got == 0 */
	BF_READ_FAILED,   /* reading the input failed */
	BF_READ_NO_DATA,  /* no data and not at EOF: non-blocking input is not supported */
	BF_READ_RAISED    /* the host raised; the run stops with no bf_error */
} bf_read_status;

/* What emit_row asks of the run after a matched row (SPEC 6.1): go on, pause after this row, or stop because the
   row budget of SPEC 6.4 refused (the row was not built). */
typedef enum { BF_EMIT_CONTINUE, BF_EMIT_PAUSE, BF_EMIT_NO_MEMORY } bf_emit_status;

/*
 * Allocation through the host happens only on growth, so no indirect call reaches the scan row loops. emit_row is
 * called only by the rows loop of toArray and a cursor, once per matched row, with its values in schema order.
 */
typedef struct {
	void *(*alloc)(void *ud, size_t n);
	void *(*realloc)(void *ud, void *p, size_t n);
	void (*free)(void *ud, void *p);
	bool (*mem_fits)(void *ud, size_t n);
	bf_read_status (*read)(void *ud, char *buf, size_t want, size_t *got);
	bool (*strtod)(void *ud, const char *s, size_t n, double *d);
	bool (*interrupted)(void *ud);
	bf_emit_status (*emit_row)(void *ud, const bf_val *values, size_t n);
	void *ud;
} bf_host;

/* ZendMM's chunk and page sizes and its largest small (bin) size; php_run.c checks them against zend_alloc.h at
   compile time. */
#define BF_MM_CHUNK ((size_t)2 * 1024 * 1024)
#define BF_MM_PAGE  ((size_t)4096)
#define BF_MM_MAX_SMALL ((size_t)3072)

/*
 * The memory_limit rule of SPEC 6.4 over ZendMM's counters: real (mapped) bytes, used (allocated) bytes and the
 * limit; the caller prices one block per call. A small block (a bin slot) comes out of free pages, so it is compared
 * unrounded against used bytes; with no headroom for another chunk a request above half the average free space per
 * chunk is refused too. Every larger block is charged in whole chunks against real bytes: a large block (a run of
 * pages) needs a run long enough in one chunk, and ZendMM's free-page map is private, so it is priced as a fresh
 * chunk (the PHP host then tries a refused one for real, php_run.c); a huge block is mapped on its own. After a
 * crossing, with real bytes above the limit, every request is refused.
 */
static inline bool bf_mem_rule(size_t real, size_t used, size_t limit, size_t request)
{
	if (real > limit || request > limit) {
		return false;
	}
	if (request <= BF_MM_MAX_SMALL) {
		size_t chunks = (real + BF_MM_CHUNK - 1) / BF_MM_CHUNK;
		size_t free_bytes = real > used ? real - used : 0;

		if (real + BF_MM_CHUNK > limit && request > free_bytes / 2 / (chunks ? chunks : 1)) {
			return false;
		}
		return used <= limit && request <= limit - used;
	}
	return ((request + (BF_MM_CHUNK - 1)) & ~(BF_MM_CHUNK - 1)) <= limit - real;
}

/* ---- errors ---- */

typedef enum {
	BF_E_NONE = 0,
	/* header */
	BF_E_MISSING_HEADER,
	BF_E_TOO_MANY_COLUMNS,
	BF_E_DUPLICATE_COLUMN,     /* column = schema index */
	BF_E_UNEXPECTED_COLUMN,    /* n1 = 1-based file position */
	BF_E_MISSING_COLUMN,       /* column = schema index */
	/* framing */
	BF_E_BARE_QUOTE,
	BF_E_UNTERMINATED_QUOTE,
	BF_E_TEXT_AFTER_QUOTE,
	BF_E_BARE_CR,
	BF_E_FIELD_COUNT_LOW,      /* n1 = expected, n2 = got */
	BF_E_FIELD_COUNT_HIGH,     /* n1 = expected */
	/* values */
	BF_E_BAD_VALUE,            /* column; n1 = bf_value_kind */
	BF_E_BAD_UTF8,             /* column */
	/* limits */
	BF_E_ROW_TOO_LONG,
	BF_E_FIELD_TOO_LONG,       /* column */
	BF_E_INPUT_ROWS,
	BF_E_MEMORY,
	/* aggregates */
	BF_E_SUM_INT_OVERFLOW,     /* column */
	BF_E_SUM_NOT_FINITE,       /* column */
	/* group-by */
	BF_E_MAX_GROUPS,           /* n1 = maxGroups */
	BF_E_GROUP_BYTES,
	/* toArray */
	BF_E_MAX_ROWS,             /* n1 = maxRows */
	/* source */
	BF_E_READ_FAILED,
	BF_E_NO_DATA,
	BF_E_TIMED_OUT,
	/* Table load */
	BF_E_TABLE_BYTES,
	BF_E_DICT_SIZE             /* column */
} bf_reason;

typedef enum {
	BF_KIND_MALFORMED,
	BF_KIND_QUOTED_EMPTY,
	BF_KIND_OUT_OF_RANGE
} bf_value_kind;

/* Whether a record was current when the error was raised. */
/* BF_WHERE_TABLE_ROW is set by the glue on a table run's row error: prefix "row R: " (SPEC 5.11). */
typedef enum { BF_WHERE_NONE, BF_WHERE_HEADER, BF_WHERE_ROW, BF_WHERE_TABLE_ROW } bf_where;

typedef struct {
	bf_reason reason;
	bf_where where;
	uint64_t row;        /* 1-based, data rows only */
	uint64_t line;
	uint64_t offset;
	int32_t column;      /* schema index, or -1 */
	uint64_t n1, n2;
} bf_error;

/* ---- run ---- */

#define BF_CHUNK          ((size_t)65536)
#define BF_BLOCK          ((size_t)64)
#define BF_INITIAL_BUFFER ((size_t)256 * 1024)

typedef struct {
	char *p;
	size_t len;
	bool quoted, doubled;
} bf_field;

typedef struct {
	uint32_t column;     /* schema index */
	uint32_t file_idx;
	bf_type type;
} bf_ref;

/* ---- hash and group table (SPEC 6.3) ---- */

/* SipHash-1-3 with a 128-bit key; words little-endian. The int form hashes v's 8 little-endian bytes. */
uint64_t bf_siphash13(const uint64_t key[2], const void *data, size_t n);
uint64_t bf_siphash13_u64(const uint64_t key[2], uint64_t v);

/* The same function computed incrementally: init, any split of the bytes into updates, final. */
typedef struct {
	uint64_t v0, v1, v2, v3;
	uint64_t tail;       /* the pending bytes of a partial word, little-endian */
	size_t total;        /* bytes hashed so far */
} bf_sip;

void bf_sip_init(bf_sip *s, const uint64_t key[2]);
void bf_sip_update(bf_sip *s, const void *data, size_t n);
uint64_t bf_sip_final(const bf_sip *s);

#define BF_GROUP_SLOTS0 ((size_t)1024)
#define BF_GROUP_CAP0   ((uint32_t)256)
#define BF_GROUP_ARENA0 ((size_t)65536)

/*
 * Single-key group table: open addressing, linear probing, slot = (high 32 hash bits << 32) | (gid + 1),
 * 0 empty. Dense group ids in first-seen order index the per-group arrays. NULL is never in the slots.
 */
typedef struct {
	uint64_t *slots;     /* a shared empty slot until the first keyed group */
	size_t mask;         /* slot count - 1 */
	uint32_t n;          /* groups, the NULL group included */
	uint32_t keyed;      /* groups in the slot table */
	uint32_t cap;        /* capacity of the per-group arrays */
	int64_t null_gid;    /* -1 until a NULL key is seen */
	bool int_keys, has_sum, sum_int;
	uint64_t *hash;      /* full hash per group, for rehashing */
	int64_t *ikey;       /* int keys */
	uint64_t *off;       /* string keys: arena offset and length */
	uint32_t *len;
	uint64_t *count;
	int64_t *isum;
	double *fsum;
	char *arena;
	size_t arena_len, arena_cap;
} bf_groups;

/*
 * groupAgg tuple encoding (SPEC 6.3), per key in $keys order: a tag byte, then nothing for NULL, the 8-byte
 * little-endian value for int64, or a 4-byte little-endian length and the bytes for a string.
 */
enum { BF_TUPLE_NULL = 0, BF_TUPLE_INT = 1, BF_TUPLE_STR = 2, BF_TUPLE_DICT = 3 };

static inline void bf_put_le(unsigned char *p, uint64_t v, unsigned n)
{
	for (unsigned i = 0; i < n; i++) {
		p[i] = (unsigned char)(v >> (8 * i));
	}
}

static inline uint64_t bf_get_le(const unsigned char *p, unsigned n)
{
	uint64_t v = 0;

	for (unsigned i = 0; i < n; i++) {
		v |= (uint64_t)p[i] << (8 * i);
	}
	return v;
}

/*
 * groupAgg table: the slots, probing, growth and tag of bf_groups over the encoded key tuple, every tuple
 * (all-NULL included) in the slots. Per group: hash, the tuple's arena offset and length, the row count and
 * one bf_agg_state per aggregate pair (16 bytes: value and non-NULL count).
 */
typedef struct {
	uint64_t *slots;     /* a shared empty slot until the first group */
	size_t mask;
	uint32_t n;          /* groups */
	uint32_t cap;        /* capacity of the per-group arrays */
	uint64_t *hash;
	uint64_t *off;
	uint32_t *len;
	uint64_t *count;
	bf_agg_state *state; /* cap * naggs entries, group-major */
	char *arena;
	size_t arena_len, arena_cap;
} bf_tuples;

/* A predicate on a dictionary column resolved to ids at the start of a table run (SPEC 6.6). */
enum { BF_T_EQ, BF_T_NE, BF_T_IN };
typedef struct {
	uint32_t col;        /* schema column */
	uint8_t kind;        /* BF_T_EQ, BF_T_NE, BF_T_IN */
	bool null_match;     /* BF_T_IN: the list held null */
	uint32_t id;         /* EQ, NE: the literal's id, UINT32_MAX when absent from the dictionary */
	uint32_t n;          /* IN: found ids, sorted */
	uint32_t *ids;
} bf_tpred;

struct bf_table;

/* SPEC 6.3: a matched row of a single-key group-by waiting for its group probe. A string key longer than BF_GB_KEY
   is not batched. */
#define BF_GB_MAX 16
#define BF_GB_KEY 48
/* A table with fewer slots than this stays in cache: its rows are probed at once, not queued. */
#define BF_GB_SLOTS_MIN ((size_t)16384)
typedef struct {
	uint64_t h;          /* the key's hash, when it is not NULL */
	bf_val key;          /* a string key points at s below */
	bool sum_null;
	int64_t isum;
	double fsum;
	uint64_t row, line, offset; /* the row's position, for an error at its probe */
	char s[BF_GB_KEY];
} bf_gentry;

/* One CSV run. The glue zeroes it and sets the query block; everything else is owned by the core. */
typedef struct {
	/* query block, borrowed for the duration of the run */
	const bf_host *host;
	const bf_col *cols;
	uint32_t ncols;
	bool allow_extra;
	size_t max_field_bytes;
	size_t max_row_bytes;
	uint64_t max_input_rows;
	bf_masks_fn masks_fn;
	bf_tail_fn tail_fn;  /* set by the core from masks_fn */
	size_t tail_from;    /* fields framed before the tail starts, SIZE_MAX when the tail is not counted by popcount */
	unsigned char delim; /* the field delimiter byte of the plan */
	const bf_pred *pred_src;
	uint32_t npreds;
	int32_t sum_col;     /* schema index, or -1 */
	int32_t group_col;   /* schema index of the group key, or -1 */
	const bf_agg *aggs;  /* aggregate pairs of aggregate() or groupAgg() */
	uint32_t naggs;
	const uint32_t *gkeys; /* groupAgg key columns (schema indexes), in $keys order */
	uint32_t ngkeys;     /* 0 unless groupAgg */
	uint32_t max_groups;
	uint64_t max_group_bytes;
	uint64_t hash_key[2];
	bool rows;           /* toArray or a cursor: every schema column referenced, matched rows to emit_row */
	uint64_t max_rows;   /* toArray's maxRows; UINT64_MAX for a cursor */

	/* reader */
	char *buf;           /* contiguous data, always followed by BF_BLOCK zero bytes */
	size_t cap, pos, fill;
	size_t bound;        /* maxRowBytes + 2 + BF_CHUNK + BF_BLOCK */
	bool eof;
	uint64_t *masks;
	size_t mask_cap, nmasks;
	size_t blk;
	uint64_t cur;
	uint64_t base;       /* offset of buf[0] from the start of this run's reading */

	/* framing */
	bf_where where;
	uint64_t line;       /* line of the current record's first byte */
	uint64_t scanned;    /* data rows framed so far */
	uint64_t row_line;   /* the committed row's line */
	uint64_t row_offset; /* the committed row's offset */
	bf_field *fields;
	size_t fields_cap;
	bool rows_started;   /* bf_run_rows has read the header */

	/* resolved after the header */
	uint32_t nfile;
	int32_t *col_file;   /* schema index to file position, -1 until seen */
	bf_ref *refs;
	uint32_t nrefs;
	bf_val *vals;
	bf_pred preds[BF_MAX_PREDS];
	int32_t sum_slot;
	int32_t group_slot;
	bool agg_mode;       /* aggregate() or groupAgg() */
	uint32_t agg_slot[BF_MAX_AGGS];
	bool agg_int[BF_MAX_AGGS];
	uint32_t gkey_slot[BF_MAX_GROUP_KEYS];

	/* results */
	uint64_t matched;
	int64_t isum;
	double fsum;
	bf_agg_state agg[BF_MAX_AGGS];
	bf_groups groups;
	bf_gentry gb[BF_GB_MAX]; /* group probes waiting for the batch to fill */
	uint32_t gb_n, gb_batch; /* queued, and the batch size (1 to BF_GB_MAX) */
	size_t gb_min_slots;     /* rows are queued once the group table has this many slots */
	bf_tuples tuples;
	bf_error err;

	/* Table runs (SPEC 6.6), set by the glue: table; the rest is owned by the core. Kept last, so the CSV loops
	   above address the same fields at the same offsets. */
	const struct bf_table *table;
	uint64_t trow;       /* the next table row */
	bf_tpred *tpreds;    /* dictionary predicates, checked before the others */
	uint32_t ntpreds;
	bool dgroup;         /* groupCount or groupSum keyed on a dictionary column: arrays indexed by id */
	uint64_t *dcount;
	int64_t *disum;
	double *dfsum;
	uint32_t *dorder;    /* ids in first-seen order among matching rows; id 0 is the NULL group */
	uint32_t ndorder;
} bf_run;

/* Value parsers return 0 or a positive bf_value_kind + 1 on rejection. */
int bf_parse_int64(const char *s, size_t n, int64_t *out);
int bf_parse_float64(const bf_host *host, const char *s, size_t n, double *out);
size_t bf_unquote_inplace(char *p, size_t len);

/* bf_csv.c: 1 = row framed, 0 = end of input, -1 = failed (err set, or host raised). */
int bf_csv_open(bf_run *r);
int bf_csv_next_row(bf_run *r);
/* The same framing with the delimiter a constant comma; only for a plan whose delimiter is ','. */
int bf_csv_next_row_comma(bf_run *r);
int bf_csv_next_row_tail(bf_run *r);
int bf_csv_next_row_comma_tail(bf_run *r);
int bf_run_alloc(bf_run *r, void **out, size_t size);
int bf_fail_row(bf_run *r, bf_reason reason, int32_t column, uint64_t n1, uint64_t n2);
int bf_fail_now(bf_run *r, bf_reason reason);

/* bf_groups.c */
void bf_groups_prepare(bf_run *r);
/* The group of the committed row's key value v, inserted at first sight. 0, or -1 with err set. */
int bf_group_of(bf_run *r, const bf_val *v, uint32_t *gid);
/* SPEC 6.3: bf_group_of in two parts, for a batch of rows whose hashes are computed and whose slots are prefetched
   before the first is probed. v is not NULL. */
uint64_t bf_group_hash(const bf_run *r, const bf_val *v);
int bf_group_probe(bf_run *r, const bf_val *v, uint64_t h, uint32_t *gid);
void bf_groups_release(bf_run *r);
void bf_tuples_prepare(bf_run *r);
/* The group of the committed row's key tuple, inserted at first sight. 0, or -1 with err set. */
int bf_tuple_of(bf_run *r, uint32_t *gid);
/* The same in a table run: a dictionary key contributes tag 3 and its id (SPEC 6.6). */
int bf_tuple_of_table(bf_run *r, uint32_t *gid);
void bf_tuples_release(bf_run *r);
/* Whether a pair's aggregate is a sum checked for finiteness after the scan: sum or avg on float64. */
static inline bool bf_agg_float_sum(const bf_agg *a, bool is_int)
{
	return !is_int && (a->op == BF_AGG_SUM || a->op == BF_AGG_AVG);
}
#if defined(BF_FUZZ_HASH_SHIM)
/* Fuzz builds only: every key hashes alike, so the table is correct by full comparison alone. */
extern bool bf_fuzz_identical_hash;
extern size_t bf_fuzz_tail_min;
extern uint32_t bf_fuzz_group_batch;
extern size_t bf_fuzz_group_min_slots;
#endif

/* bf_exec.c: true on success; false with err.reason set, or BF_E_NONE when the host raised. */
bool bf_run_scan(bf_run *r);
/*
 * toArray and a cursor (r->rows): each matched row, in input order, to emit_row. The first call reads the header; a
 * call after a pause continues at the next row. 1 = paused after an emitted row, 0 = end of input, -1 = failed with
 * err set, or BF_E_NONE when the host raised.
 */
int bf_run_rows(bf_run *r);
void bf_run_release(bf_run *r);

/* ---- Table column store (SPEC 6.6) ---- */

#define BF_TABLE_ROWS0  ((uint64_t)4096)
#define BF_TABLE_ARENA0 ((size_t)65536)

/* Capacity bytes of a single-key group table: slots, per-group arrays and key arena (SPEC 6.4). */
uint64_t bf_groups_bytes(const bf_groups *g);
/* Read-only lookup of a non-NULL key under the table's hash key: its gid, or -1 when absent. */
int64_t bf_groups_find(const bf_groups *g, const uint64_t key[2], const bf_val *v);

typedef struct {
	bf_type type;
	bool dict;
	uint8_t *valid;        /* per row: 1 present, 0 NULL; a NULL slot holds zero in every array */
	int64_t *i;            /* int64, a dictionary int64 column included */
	double *d;
	uint8_t *b;
	uint64_t *off;         /* plain string: offset into the table arena and length */
	uint32_t *len;
	uint32_t *ids;         /* dictionary: 0 NULL, gid + 1 */
	bf_groups values;      /* dictionary: the distinct non-NULL values, gid in first-seen order */
} bf_tcol;

/*
 * A loaded table: rows double from 4096, clamped to maxInputRows; the string arena starts at 65,536 bytes and
 * doubles until the request fits. bytes is the sum of every capacity, the value of memoryBytes() and what
 * maxTableBytes bounds. Nothing is allocated before the first row. The glue zeroes it and sets the first block.
 */
typedef struct bf_table {
	bf_host host;          /* a copy: the table outlives the load run */
	const bf_col *cols;
	uint32_t ncols;
	uint64_t max_bytes;    /* maxTableBytes */
	uint64_t max_rows;     /* maxInputRows */
	uint32_t max_dict;     /* maxDictionarySize */
	uint64_t hash_key[2];  /* the table's own SipHash key, for its dictionaries */

	bf_tcol *tcols;
	uint64_t row_bytes;    /* capacity bytes per row over all columns */
	uint64_t rows, cap;
	char *arena;
	size_t arena_len, arena_cap;
	uint64_t bytes;
	bf_run *dict_run;      /* scratch run through which dictionary values enter their group table */
} bf_table;

/* Column descriptors; dict[c] marks a dictionary column. 0, or -1 with r->err set (memory). */
int bf_table_init(bf_table *t, bf_run *r, const bool *dict);
/* Appends one decoded row in schema order. 0, or -1 with r->err set and the committed row's context. */
int bf_table_append(bf_table *t, bf_run *r, const bf_val *vals);
void bf_table_free(bf_table *t);

/*
 * Table runs (SPEC 6.6): the glue sets the query block and r->table. bf_run_table_scan computes every terminal but
 * toArray; bf_run_table_rows is the rows loop of toArray and a cursor (1 paused, 0 end, -1 failed). Errors at a row
 * carry where BF_WHERE_ROW and row = the 1-based table row. bf_run_table_release frees what either allocated.
 */
bool bf_run_table_scan(bf_run *r);
int bf_run_table_rows(bf_run *r);
void bf_run_table_release(bf_run *r);

#endif
