@php $title = 'Контент'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Страницы и SEO-поля. Сохранение контента будет реализовано отдельной задачей.',
])

@section('section-content')
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Path</th>
                    <th>Страница</th>
                    <th>SEO title</th>
                    <th>Keywords</th>
                    <th>Description</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pages as $page)
                    <tr>
                        <td>{{ $page['path'] }}</td>
                        <td>{{ $page['title'] }}</td>
                        <td>{{ $page['seo_title'] }}</td>
                        <td>{{ $page['keywords'] }}</td>
                        <td>{{ $page['description'] }}</td>
                        <td><span class="admin-badge">{{ $page['status'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
