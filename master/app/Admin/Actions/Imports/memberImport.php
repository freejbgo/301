<?php

namespace App\Admin\Actions\Imports;

use App\Models\Member;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;

class memberImport implements ToModel, WithStartRow
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // 0代表的是第一列 以此类推
        // $row 是每一行的数据
        //查询是否存在，存在就不写入
        $user = Member::where('original_url', '=', $row[1])->first();
        if ($user) {
            return null;
        }
        return new Member([
            'group_id' => $row[0],
            'original_url' => $row[1],
            'skip_url' => $row[2],
            'status' => $row[3],
            'admin_id' => $row[4],
        ]);

    }


    /**
     * 从第几行开始处理数据 就是不处理标题
     * @return int
     */
    public function startRow(): int
    {
        return 2;
    }
}