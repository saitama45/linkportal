<?php

return [
    'bdo' => [
        'name' => 'BDO Unibank',
        'columns' => [
            'date'        => 'Date',
            'description' => 'Particulars',
            'reference'   => 'Reference',
            'debit'       => 'Debit',
            'credit'      => 'Credit',
            'balance'     => 'Balance',
        ],
        'date_format' => 'M d, Y',
        'skip_rows'   => 3,
    ],

    'bpi' => [
        'name' => 'Bank of the Philippine Islands',
        'columns' => [
            'date'        => 'Date',
            'description' => 'Description',
            'reference'   => null,
            'debit'       => 'Debit',
            'credit'      => 'Credit',
            'balance'     => 'Running Balance',
        ],
        'date_format' => 'm/d/Y',
        'skip_rows'   => 1,
    ],

    'metrobank' => [
        'name' => 'Metrobank',
        'columns' => [
            'date'        => 'Trans. Date',
            'description' => 'Description',
            'reference'   => 'Reference No.',
            'debit'       => 'Debit',
            'credit'      => 'Credit',
            'balance'     => 'Balance',
        ],
        'date_format' => 'm/d/Y',
        'skip_rows'   => 2,
    ],

    'unionbank' => [
        'name' => 'UnionBank',
        'columns' => [
            'date'        => 'Date',
            'description' => 'Narrative',
            'reference'   => null,
            'debit'       => 'Debit Amount',
            'credit'      => 'Credit Amount',
            'balance'     => 'Balance',
        ],
        'date_format' => 'd-M-Y',
        'skip_rows'   => 1,
    ],

    'landbank' => [
        'name' => 'Land Bank of the Philippines',
        'columns' => [
            'date'        => 'Transaction Date',
            'description' => 'Description',
            'reference'   => 'Reference Number',
            'debit'       => 'Debit',
            'credit'      => 'Credit',
            'balance'     => 'Balance',
        ],
        'date_format' => 'm/d/Y',
        'skip_rows'   => 2,
    ],

    'pnb' => [
        'name' => 'Philippine National Bank',
        'columns' => [
            'date'        => 'Date',
            'description' => 'Particulars',
            'reference'   => 'Check/Ref No.',
            'debit'       => 'Withdrawal',
            'credit'      => 'Deposit',
            'balance'     => 'Balance',
        ],
        'date_format' => 'm/d/Y',
        'skip_rows'   => 1,
    ],
];
