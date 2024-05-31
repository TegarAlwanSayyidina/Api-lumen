<?php

namespace App\Http\Controllers;

use App\Helpers\ApiFormatter;
use App\Models\Stuff;
use App\Models\Inbounstuff;
use App\Models\StuffStock;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InboundStuffController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }


    public function index (Request $request)
    {
        try {
            if($request->filter_id) {
        $data = Inbounstuff::where('stuff_id',$request->filter_id)->with('stuff','stuff.stuffStock')->get();
    } else {
        $data = Inbounstuff::all();
    }
    return ApiFormatter::sendResponse(200, 'success', $data);
    }catch(\Exception $err){
        return ApiFormatter::sendResponse(400, 'bad request', $err->getMessage());
    }
    }

    public function store(Request $request)
    {
    try {
        $this->validate($request, [
            'stuff_id' => 'required',
            'total' => 'required',
            'date' => 'required',
            'proof_file' => 'required|mimes:jpeg,png,jpg,pdf|max:2048',
        ]);

        if($request->hasFile('proof_file')) {
            $proof = $request->file('proof_file');
            $destinationPath = 'proof/';
            $proofName = date('YmdHis') . "." . $proof->getClientOriginalExtension();
            $proof->move($destinationPath, $proofName);
        }
        $createStock = Inbounstuff::create([
            'stuff_id' => $request->stuff_id,
            'total' => $request->total,
            'date' => $request->date,
            'proof_file' => $proofName,
        ]);

        if ($createStock){
            $getStuff = Stuff::where('id', $request->stuff_id)->first();
            $getStuffStock = StuffStock::where('stuff_id', $request->stuff_id)->first();

            if (!$getStuffStock){
                $updateStock = StuffStock::create([
                    'stuff_id' => $request->stuff_id,
                    'total_available' => $request->total,
                    'total_defec' => 0,
                ]);
            } else {
                $updateStock = $getStuffStock->update([
                    'stuff_id' => $request->stuff_id,
                    'total_available' =>$getStuffStock['total_available'] + $request->total,
                    'total_defec' => $getStuffStock['total_defec'],
                ]);
            }

            if ($updateStock) {
                $getStock = StuffStock::where('stuff_id', $request->stuff_id)->first();
                $stuff = [
                    'stuff' => $getStuff,
                    'InboundStuff' => $createStock,
                    'stuffStock' => $getStock
                ];

                return ApiFormatter::sendResponse(200, 'Successfully Create A Inbound Stuff Data', $stuff);
            } else {
                return ApiFormatter::sendResponse(400, false, 'Failed To Update A Stuff Stock Data');
            }
        } else {
        }
    } catch (\Exception $e) {
        return ApiFormatter::sendResponse(400, false, $e->getMessage());
   } 
}

    public function destroy($id)
    {
        try {
            $inboundData = Inbounstuff::where('id', $id)->first();
            //simpan data dari inbound yang diperlukan /akan digunakan nanti setelah delete
            $stuffId = $inboundData['stuff_id'];
            $totalInbound = $inboundData['total'];
            $inboundData->delete();

            //kurangin total_avalable sebelumnya dengan total dari inbound yang akan dihapus
            $dataStock = StuffStock::where('stuff_id', $inboundData['stuff_id'])->first();
            $total_available = (int)$inboundData['total_available'] - (int) $totalInbound;

            $minusTotalStock = $dataStock->update(['total_available'=> $total_available]);

            if ($minusTotalStock) {
                $updatedStuffWithInboundAndStock = Stuff::where('id',$stuffId)->with('inboundStuffs','stuffStock')
                ->first();
                //delete inbound
                // $inboundData->delete();
                return ApiFormatter::sendResponse(200,'success',$updatedStuffWithInboundAndStock);
            }
        }catch (\Exception $err) {
            return ApiFormatter::sendResponse(400, 'bad request', $err->getMessage());
        }
    }

    public function trash()
    {
        try{
            $data= Inbounstuff::onlyTrashed()->get();

            return ApiFormatter::sendResponse(200, 'success', $data);
        }catch(\Exception $err){
            return ApiFormatter::sendResponse(400, 'bad request', $err->getMessage());
        }
    }

    public function restore(Inbounstuff $inboundStuff, $id)
    {
        try {
            // Memulihkan data dari tabel 'inbound_stuffs'
            $checkProses = Inbounstuff::onlyTrashed()->where('id', $id)->restore();

            if ($checkProses) {
                // Mendapatkan data yang dipulihkan
                $restoredData = Inbounstuff::find($id);

                // Mengambil total dari data yang dipulihkan
                $totalRestored = $restoredData->total;

                // Mendapatkan stuff_id dari data yang dipulihkan
                $stuffId = $restoredData->stuff_id;

                // Memperbarui total_available di tabel 'stuff_stocks'
                $stuffStock = StuffStock::where('stuff_id', $stuffId)->first();

                if ($stuffStock) {
                    // Menambahkan total yang dipulihkan ke total_available
                    $stuffStock->total_available += $totalRestored;

                    // Menyimpan perubahan pada stuff_stocks
                    $stuffStock->save();
                }

                return ApiFormatter::sendResponse(200, 'success', $restoredData);
            } else {
                return ApiFormatter::sendResponse(400, 'bad request', 'Gagal mengembalikan data!');
            }
        } catch (\Exception $err) {
            return ApiFormatter::sendResponse(400, 'bad request', $err->getMessage());
        }
    }

   public function permanentDelete(Inbounstuff $inboundStuff, Request $request, $id)
{
    try {
        $inboundData = Inbounstuff::onlyTrashed()->findOrFail($id);
        $proofFilePath = base_path('public/proof/' . $inboundData->proof_file);

        if (file_exists($proofFilePath)) {
            unlink($proofFilePath); // Hapus file dari storage
        }

        $inboundData->forceDelete(); // Hapus data dari database secara permanen

        return ApiFormatter::sendResponse(200, 'success', 'Data inbound-stuff berhasil dihapus permanen');
    } catch (\Exception $err) {
        return ApiFormatter::sendResponse(400, 'bad request', $err->getMessage());
    }
}


}
