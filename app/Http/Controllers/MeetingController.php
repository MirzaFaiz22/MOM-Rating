<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Meeting;
use Illuminate\Http\Request;
use App\Mail\RatingInvitation;
use App\Models\MeetingParticipant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator; 

class MeetingController extends Controller
{

    protected $googleMeetController;

    public function __construct(GoogleMeetController $googleMeetController)
    {
        $this->googleMeetController = $googleMeetController;
    }
    public function index()
    {
        

        return view('meetings.index_rapat');
    }

    public function create()
    {
        $users = User::all();
        return view('meetings.buat_rapat', compact('users'));
    }

    public function upload(Meeting $meeting, Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'attachment_link' => 'nullable|url',
            ], [
                'attachment.max' => 'Ukuran file tidak boleh lebih dari 2MB.',
                'attachment.mimes' => 'Format file harus berupa PDF, JPG, JPEG, atau PNG.',
                'attachment_link.url' => 'Link yang diberikan tidak valid.',
            ]);

            if ($validator->fails()) {
                return redirect()->route('meetings.show', $meeting->id)
                    ->withErrors($validator)
                    ->withInput();
            }

            $data = [];

            if ($request->hasFile('attachment')) {
                $path = $request->file('attachment')->store('meeting-attachments', 'public');
                $data['attachment'] = $path;
            }

            if ($request->filled('attachment_link')) {
                $data['attachment_link'] = $request->attachment_link;
            }

            if (empty($data)) {
                return redirect()->route('meetings.show', $meeting->id)
                    ->with('error', 'Harap unggah file atau isi link terlebih dahulu.');
            }

            $updated = $meeting->update($data);

            if (!$updated) {
                return redirect()->route('meetings.show', $meeting->id)
                    ->with('error', 'Gagal menyimpan data.');
            }

            return redirect()->route('meetings.show', $meeting->id)
                ->with('success', 'Lampiran berhasil diunggah.');

        } catch (\Exception $e) {
            return redirect()->route('meetings.show', $meeting->id)
                ->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    

    public function complete(Meeting $meeting)
    {
        try {
            // Update status meeting ke 'completed'
            $updated = $meeting->update([
                'status' => 'completed'
            ]);
            
            if (!$updated) {
                return back()->with('error', 'Gagal mengubah status meeting');
            }

            return redirect()->route('meetings.index', $meeting->id)
                ->with('success', 'Meeting telah selesai.');
        } catch (\Exception $e) {
            Log::error('Error in complete method: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function getUsers(Request $request)
    {
        $search = $request->name;
        
        if ($search) {
            $peserta = User::where('name', 'LIKE', "%$search%")->get();
        } else {
            $peserta = User::all();
        }
        
        return response()->json($peserta);
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_rapat' => 'required|string',
            'jenis_rapat' => 'required|in:offline,online',
            'google_meet_link' => 'nullable|url',
            'google_event_id' => 'nullable|string',
            'agenda_rapat' => 'required|string',
            'tempat_rapat' => 'nullable|string',
            'tanggal_mulai' => 'required|date',
            'tanggal_berakhir' => 'required|date|after_or_equal:tanggal_mulai',
            'jam_mulai' => 'required',
            'jam_berakhir' => 'required|after:jam_mulai',
            'catatan' => 'nullable|string',
            'nama_pic' => 'required',
            'peserta' => 'required',
        ]);

        $validated['peserta'] = json_encode($request->peserta);
        $validated['nama_pic'] = json_encode($request->nama_pic);
        
        $meeting = Meeting::create($validated);

        // Array untuk menyimpan semua email
        $emails = [];

        // Simpan dan ambil email peserta
        foreach ($request->peserta as $pesertaId) {
            $participant = MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'user_id' => $pesertaId,
            ]);
            
            // Ambil email peserta
            $user = User::find($pesertaId);
            if ($user && $user->email) {
                $emails[] = $user->email;
            }
        }

        // Simpan dan ambil email PIC
        foreach ($request->nama_pic as $picId) {
            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'user_id' => $picId,
            ]);
            
            // Ambil email PIC
            $user = User::find($picId);
            if ($user && $user->email) {
                $emails[] = $user->email;
            }
        }

        // Hapus email duplikat
        $emails = array_unique($emails);

        try {
            // Kirim email ke semua peserta dan PIC
            foreach ($emails as $email) {
                Mail::to($email)->send(new RatingInvitation($meeting));
            }
        } catch (\Exception $e) {
            Log::error('Error sending emails in store method: ' . $e->getMessage());
            // Tidak menghentikan proses meski email gagal
        }

        return redirect()->route('meetings.index')->with('success', 'Rapat berhasil dibuat dan undangan telah dikirim');
    }


    public function show(Meeting $meeting)
    {

        return view('meetings.show', compact('meeting'));
    }

    public function edit(Meeting $meeting)
    {
        $users = User::all(); 

        return view('meetings.edit', compact('meeting', 'users'));
    }



    public function update(Request $request, Meeting $meeting)
    {
        Log::info('Update request received', ['request' => $request->all()]);

        try {
            $validated = $request->validate([
                'nama_rapat' => 'required|string',
                'status' => 'required|string',
                'jenis_rapat' => 'required|in:offline,online',
                'google_meet_link' => 'nullable|url',
                'google_event_id' => 'nullable|string',
                'agenda_rapat' => 'required|string',
                'tempat_rapat' => 'nullable|string',
                'tanggal_mulai' => 'required|date',
                'tanggal_berakhir' => 'required|date|after_or_equal:tanggal_mulai',
                'jam_mulai' => 'required',
                'jam_berakhir' => 'required|after:jam_mulai',
                'catatan' => 'nullable|string',
                'nama_pic' => 'required',
                'peserta' => 'required',
            ]);

            Log::info('Validation passed', ['validated' => $validated]);

            if ($meeting->jenis_rapat === 'online' && $meeting->google_event_id) {
                try {
                    $googleMeetResponse = $this->googleMeetController->updateGoogleMeet($meeting, $request);
                    $responseData = json_decode($googleMeetResponse->getContent(), true);
                    
                    if (!$responseData['success']) {
                        Log::error('Failed to update Google Meet', $responseData);
                        return redirect()
                            ->back()
                            ->with('error', $responseData['message'])
                            ->withInput();
                    }
                } catch (\Exception $e) {
                    Log::error('Exception when updating Google Meet', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return redirect()
                        ->back()
                        ->with('error', 'Gagal mengupdate Google Meet: ' . $e->getMessage())
                        ->withInput();
                }
            }

            // Update data di database
            $validated['peserta'] = json_encode($request->peserta);
            $validated['nama_pic'] = json_encode($request->nama_pic);
            
            $meeting->update($validated);
            Log::info('Meeting updated in database', ['meeting_id' => $meeting->id]);

            // Update meeting participants
            MeetingParticipant::where('meeting_id', $meeting->id)->delete();
            Log::info('Old participants deleted');

            // Tambah participants baru
            foreach ($request->peserta as $pesertaId) {
                MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $pesertaId,
                ]);
            }
            Log::info('New participants added');

            // Tambah PIC baru
            foreach ($request->nama_pic as $picId) {
                MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $picId,
                ]);
            }
            Log::info('New PICs added');

            return redirect()->route('meetings.index')->with('success', 'Rapat berhasil diupdate');

        } catch (\Exception $e) {
            Log::error('Error in update method', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()
                ->with('error', 'Terjadi kesalahan: ' . $e->getMessage())
                ->withInput();
        }
    }


    public function destroy(Meeting $meeting)
    {
        if ($meeting->jenis_rapat === 'online' && $meeting->google_event_id) {
            try {
                $googleMeetResponse = $this->googleMeetController->deleteGoogleMeet($meeting->google_event_id);
                $responseData = json_decode($googleMeetResponse->getContent(), true);
                
                if (!$responseData['success']) {
                    return redirect()->back()->with('error', 'Gagal menghapus Google Meet');
                }
            } catch (\Exception $e) {
                return redirect()->back()->with('error', 'Gagal menghapus Google Meet: ' . $e->getMessage());
            }
        }
        // Hapus meeting participants
            MeetingParticipant::where('meeting_id', $meeting->id)->delete();
            
            // Hapus meeting
            $meeting->delete();
            return redirect()->route('meetings.index')->with('success', 'Rapat berhasil dihapus');
        
    }


    public function ratingPage()
    {        
        return view('rating');
    }

    public function saveNotes(Request $request, $id)
    {
        $meeting = Meeting::findOrFail($id); // Ambil data rapat berdasarkan ID
        $meeting->notes = $request->input('notes'); // Perbarui field `notes`
        $meeting->save(); // Simpan perubahan ke database

        return redirect()->back()->with('success', 'Notulensi berhasil diperbarui!');
    }


    // use Barryvdh\DomPDF\Facade\Pdf;

    // public function download(Meeting $meeting)
    // {
    //     $pdf = PDF::loadView('meetings.pdf', compact('meeting'));
    //     return $pdf->download('notulensi-rapat-' . $meeting->id . '.pdf');
    // }

    

}
