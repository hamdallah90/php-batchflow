#!/usr/bin/env python3
"""Writes fuzz/corpus/seed/*: 48 config bytes then a CSV body. See fuzz_batchflow.c for the layout."""
import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'corpus', 'seed')
os.makedirs(OUT, exist_ok=True)

def cfg(ncols, types, sum_col=None, npreds=0, preds=(), auto_header=True, allow_extra=False,
        row=0x10, field=0x10, inputs=0x80, chunk=0, group=None, max_groups=0, group_bytes=None,
        delim=0, mode=0, pairs=(), keys=(), inset=()):
    b = [0] * 48
    b[0] = ((ncols - 1) & 7) | (0x40 if auto_header else 0) | (0x80 if allow_extra else 0)
    for i, t in enumerate(types):
        b[1 + i // 4] |= t << ((i % 4) * 2)
    b[3] = row
    b[4] = field
    b[5] = inputs
    if sum_col is not None:
        b[6] = 8 | sum_col
    b[7] = (npreds & 3) << 5
    for i, (v, sel) in enumerate(preds):
        b[8 + i * 2] = v
        b[9 + i * 2] = sel
    # byte 14 is maxGroups: bit 7 means 100,000,000, else (b & 0x7F) + 1
    if group is not None:
        b[7] |= 0x10 | group
    b[14] = max_groups
    if group_bytes is not None:
        b[5] |= 0x40
        b[3] |= group_bytes << 5
    b[15] = chunk
    # byte 16: delimiter (0 , 1 ; 2 tab 3 |), mode (1 aggregate, 2 groupAgg, 3 toArray), pair count; 17-21 keys; 22-37 pairs
    npairs = len(pairs) - (1 if mode == 1 else 0)
    b[16] = delim | (mode << 2) | (max(npairs, 0) << 4)
    if keys:
        b[17] = len(keys) - 1
        for i, k in enumerate(keys):
            b[18 + i] = k
    for i, (op, col) in enumerate(pairs):
        b[22 + i] = op | (col << 3)
    for i, x in enumerate(inset):
        b[38 + i] = x
    return bytes(b)

# predicate selector: bits 0-2 column, bit 3 gt, bits 4-6 op (1 ne, 2 lt, 3 gte, 4 lte, 5 in), bit 7 null
NE, LT, GTE, LTE, IN = 0x10, 0x20, 0x30, 0x40, 0x50
COUNT, SUM, MIN, MAX, AVG = 0, 1, 2, 3, 4

INT, FLOAT, BOOL, STR = 0, 1, 2, 3
SEEDS = {
    'basic':       (cfg(3, [INT, STR, FLOAT], sum_col=2, npreds=1, preds=[(0, 1)]), b'1,a,1.5\n2,b,2.5\n3,"c,d",-7\n'),
    'crlf':        (cfg(2, [INT, FLOAT], sum_col=1), b'1,2.5\r\n2,3\r\n3,\r\n'),
    'quotes':      (cfg(2, [INT, STR], npreds=1, preds=[(ord('"'), 1)]), b'1,"say ""hi"""\n2,"multi\nline"\n3,""\n'),
    'errors':      (cfg(2, [INT, STR]), b'1,a\n"2"x,b\n'),
    'limits':      (cfg(2, [INT, STR], row=0x03, field=0x01, inputs=0x05), b'1,ab\n22,abcd\n'),
    'bom':         (cfg(1, [STR], auto_header=False), b'\xef\xbb\xbfc0\nvalue\n\xef\xbb\xbfdata\n'),
    'extra':       (cfg(2, [INT, STR], auto_header=False, allow_extra=True), b'x,c0,y,c1,x\n1,1,2,a,3\n'),
    'dupheader':   (cfg(2, [INT, STR], auto_header=False), b'c0,c0,c1\n1,2,3\n'),
    'utf8':        (cfg(1, [STR]), 'é🙂\n'.encode() + b'\xc0\xaf\n'),
    'floats':      (cfg(1, [FLOAT], sum_col=0), b'1e308\n1e308\n'),
    'float_digits_0': (cfg(1, [FLOAT], sum_col=0), b'9007199254740993\n'),
    'float_digits_1': (cfg(1, [FLOAT], sum_col=0), b'9007199254740993e1\n'),
    'float_digits_2': (cfg(1, [FLOAT], sum_col=0), b'123456789012345678901e-3\n'),
    'float_digits_3': (cfg(1, [FLOAT], sum_col=0), b'0.1000000000000000055511151231257827\n'),
    'float_digits_4': (cfg(1, [FLOAT], sum_col=0), b'9007199254740992e22\n'),
    'float_digits_5': (cfg(1, [FLOAT], sum_col=0), b'18014398509481985e-1\n'),
    'overflow':    (cfg(1, [INT], sum_col=0), b'9223372036854775807\n9223372036854775807\n'),
    'bools':       (cfg(2, [BOOL, STR], npreds=1, preds=[(1, 0)]), b'true,a\nfalse,b\n,c\nTRUE,d\n'),
    'crlast':      (cfg(1, [INT]), b'1\r'),
    'empty':       (cfg(1, [STR], auto_header=False), b''),
    'group_str':   (cfg(3, [STR, INT, FLOAT], sum_col=1, group=0, max_groups=0x80), b'a,1,1\nb,2,2\na,3,3\n"",4,4\n,5,5\n"a",6,6\n"a""b",7,7\n,8,8\n'),
    'group_int':   (cfg(2, [INT, FLOAT], sum_col=1, group=0, max_groups=0x80), b'7,1.5\n-7,2\n7,\n,0.25\n9223372036854775807,1\n-9223372036854775808,1\n7,1\n'),
    'group_count': (cfg(2, [STR, BOOL], npreds=1, preds=[(1, 1)], group=0, max_groups=0x80), b'x,true\ny,false\nz,true\nx,true\n,true\n'),
    'group_limit': (cfg(1, [STR], group=0, max_groups=2), b'a\nb\na\nc\nd\n'),
    'group_bytes': (cfg(1, [INT], group=0, max_groups=0x80, group_bytes=4), ''.join(f'{i}\n' for i in range(800)).encode()),
    'group_arena': (cfg(1, [STR], group=0, max_groups=0x80, group_bytes=6), b'k' * 70000 + b'\nm\n'),
    'group_many':  (cfg(2, [STR, INT], sum_col=1, group=0, max_groups=0x80), ''.join(f'k{i % 50},{i}\n' for i in range(200)).encode()),
    'group_overflow': (cfg(2, [STR, INT], sum_col=1, group=0, max_groups=0x80), b'a,9223372036854775807\nb,1\na,1\n'),
    'group_arrays0': (cfg(1, [INT], group=0, max_groups=0x80, group_bytes=1), b'5\n'),
    'group_arena0': (cfg(1, [STR], group=0, max_groups=0x80, group_bytes=5), b'ab\n'),
    'group_empty_key': (cfg(1, [STR], group=0, max_groups=0x80, group_bytes=2), b'""\n\n""\n'),
    'group_inf':   (cfg(2, [INT, FLOAT], sum_col=1, group=0, max_groups=0x80), b'1,1e308\n2,1\n1,1e308\n'),
    'delim_semicolon': (cfg(3, [INT, STR, FLOAT], sum_col=2, delim=1), b'1;"a;b";1,5\n2;x,y;2\n3;"q""r";-0.0\n'),
    'delim_tab':   (cfg(2, [STR, INT], sum_col=1, delim=2), b'a\tb,c|d\t1\n"x\ty"\t2\r\n\t3\n'),
    'delim_pipe':  (cfg(2, [INT, STR], delim=3, npreds=1, preds=[(ord('a'), 1 | NE)]), b'1|a\n2|"b|c"\n3|a,b;c\n4|\n'),
    'preds_range': (cfg(2, [INT, FLOAT], npreds=3, preds=[(2, 0 | GTE), (0xFC, 1 | LT), (40, 0 | LTE)]), b'1,-1\n2,-0.0\n3,0.0\n5,-2\n9,-9\n,\n'),
    'preds_ne_null': (cfg(3, [INT, BOOL, STR], npreds=3, preds=[(0, 0 | NE | 0x80), (1, 1 | NE), (ord('x'), 2 | NE)]), b'1,true,x\n,false,y\n3,,\n4,false,xx\n'),
    'preds_in':    (cfg(4, [INT, FLOAT, BOOL, STR], npreds=3, preds=[(1, 0 | IN | 0x80), (0xFF, 1 | IN), (0, 3 | IN)], inset=[2, 7, 2, 0, 4, 1, 1, 9, 2]),
                    b'1,0.0,true,a\n2,-0.0,false,\n,1.75,true,""\n7,0.5,,b\n3,-0.0,true,e\n'),
    'aggregate':   (cfg(4, [INT, FLOAT, BOOL, STR], mode=1, pairs=[(COUNT, 3), (SUM, 0), (SUM, 1), (MIN, 1), (MAX, 0), (AVG, 1), (COUNT, 2), (AVG, 0)]),
                    b'5,1.5,true,a\n,-0.0,false,\n-7,0.0,,b\n9223372036854775807,2,true,c\n'),
    'aggregate_overflow': (cfg(2, [INT, FLOAT], mode=1, pairs=[(MIN, 0), (SUM, 1), (AVG, 0)]), b'9223372036854775807,1e308\n1,1e308\n'),
    'group_agg':   (cfg(4, [STR, INT, FLOAT, STR], mode=2, keys=[0, 1, 3], pairs=[(SUM, 2), (COUNT, 0), (MIN, 1), (AVG, 2)], max_groups=0x80),
                    b'a,1,1.5,x\nb,2,2,x\na,1,,x\n,,1,\n"",,2,""\n,,3,\na,1,-0.0,x\n'),
    'group_agg_nokeys': (cfg(2, [INT, STR], mode=2, keys=[1, 0], max_groups=0x80), b'1,a\n2,a\n1,a\n,\n'),
    'group_agg_limit': (cfg(2, [STR, INT], mode=2, keys=[0, 1], max_groups=2), b'a,1\na,2\na,1\nb,1\n'),
    'group_agg_int_hi': (cfg(2, [INT, INT], mode=2, keys=[0], pairs=[(SUM, 1)], max_groups=0x80), b'1,1\n4294967297,2\n-4294967295,4\n1,8\n'),
    'group_agg_bytes': (cfg(2, [INT, INT], mode=2, keys=[0, 1], max_groups=0x80, group_bytes=6), ''.join(f'{i},{i * 3}\n' for i in range(4000)).encode()),
    # mode 3 is toArray(): byte 14 is maxRows, bit 7 unbounded, else (b & 0x7F) + 1
    'rows':        (cfg(4, [INT, FLOAT, BOOL, STR], mode=3, max_groups=0x80),
                    b'1,1.5,true,a\n,,,\n-9223372036854775808,-0.0,false,""\n2,1e-400,true,"x""y"\n3,5.,false,"l\nf"\n'),
    'rows_limit':  (cfg(2, [INT, STR], mode=3, max_groups=2, npreds=1, preds=[(0, 0 | 0x08)]), b'1,a\n0,b\n2,c\n3,d\n4,e\n"x",f\n'),
    'rows_bad':    (cfg(3, [STR, INT, BOOL], mode=3, max_groups=0x80, delim=1), b'a;1;true\nb;2;false\n\xff;3;true\nc;x;TRUE\n'),
    # whereEq on an int column whose first value differs from the literal: as a dictionary under the identical-hash
    # shim, every entry has the literal's tag, so the literal resolves to its id only by comparing the key itself
    # SPEC 6.2: rows with more fields after the last referenced column than the popcount tail needs, with quotes,
    # CR and field counts that make it fall back
    'tail_wide':   (cfg(8, [INT, STR, INT, STR, INT, STR, INT, STR], chunk=0), b'1,a,2,b,3,c,4,d\n2,"x,y",3,b,4,c,5,d\n3,a,"q""r",b,4,"l\nm",5,d\n4,a,2,b,3,c,4\n5,a,2,b,3,c,4,d,e\n6,a,2,b,3,c\r4,d\n7,a,2,b,3,c,4,d\r\n'),
    'tail_wide_one': (cfg(8, [INT, STR, INT, STR, INT, STR, INT, STR], chunk=1, sum_col=0), b'1,a,2,b,3,c,4,d\n2,"x,y",3,b,4,c,5,d\n3,a,2,b,3,c,4,d'),
    'dict_int_eq': (cfg(2, [INT, INT], npreds=1, preds=[(7, 0)]), b'5,1\n7,2\n9,3\n7,4\n'),
}

for name, (c, body) in SEEDS.items():
    with open(os.path.join(OUT, name), 'wb') as f:
        f.write(c + body)
